<?php

namespace App\Livewire\Operator;

use Livewire\Component;
use App\Models\Trip;
use App\Models\Kiosk;
use App\Models\Delivery;
use App\Models\Settlement;
use App\Models\KioskVisit;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;

#[Layout('layouts.operator', ['hideBottomNav' => true])]
class ActiveTrip extends Component
{
    // Konstanta domain (jangan hardcode di method)
    public const BIJI_PER_MIKA = 15;
    public const HARGA_PER_BIJI = 800;

    // Alasan valid untuk mengakhiri trip
    public const VALID_END_REASONS = ['stock_habis', 'target_done', 'sakit', 'urgent_personal', 'other'];

    // --- STATE DASAR ---
    public $trip;
    public $kiosks = [];
    public $starting_cluster_id;

    // Status per-kios disimpan terpisah dari model: Livewire re-query Eloquent
    // collection saat hydrate (lihat EloquentCollectionSynth), jadi atribut custom
    // di model akan hilang antar-request. Array primitif aman diserialisasi.
    public array $visitedKioskIds = [];
    public array $pendingKioskIds = [];

    // Flag pintar per kios: ['kioskId' => ['urgent','warning','fast_mover','slow_mover','new']]
    public array $kioskFlags = [];

    // Operator terakhir yang mengunjungi tiap kios: ['kioskId' => ['name'=>..,'date'=>..]]
    public array $lastOperatorPerKiosk = [];

    // --- STATE GEO/NEAREST NEIGHBOR ---
    public bool $sortedByDistance = false;
    public ?float $userLat = null;
    public ?float $userLng = null;

    // --- STATE TRANSAKSI KUNJUNGAN ---
    public $isVisitModalOpen = false;
    public $selectedKiosk = null;
    public $pendingDelivery = null;

    // Aksi yang dipilih operator di layar pertama modal (UI murni — aksi yang
    // TERSIMPAN tetap ditentukan resolveVisitAction() dari kondisi form).
    // null = masih di layar pilih aksi. Nilai: tagih_titip|tagih|tunda|titip|cek|cash
    public ?string $chosenAction = null;

    // Kios cash only: setiap drop langsung bayar cash (di-set dari kios terpilih)
    public bool $isCashOnly = false;

    // Input Form dari Rian
    public $returnFresh = 0;
    public $returnExpired = 0;
    public $dropBaru = 0;
    public $uangDiterima = 0;

    // Mode penanganan kelebihan drop di atas default_qty_mika (hanya kios non-cash).
    // 'cash'        = bagian default konsinyasi, sisanya cash langsung (default, backward compat).
    // 'konsinyasi'  = semua konsinyasi penuh (tidak di-split) + naikkan default_qty_mika kios.
    public string $extraDropMode = 'cash';

    // --- SKENARIO 4: turunkan default qty kios saat settle ---
    public bool $turunkanDefault = false;
    public int $qtyDefaultBaru = 0;

    // --- SKENARIO 5: check_only + alasan + sisa biji ---
    public string $alasanCheck = '';
    public int $sisaBiji = 0;

    // --- SKENARIO 7: BS redistribusi (mika BS dari kios lain ikut di-drop) ---
    public bool $adaBsRedistribusi = false;
    public int $qtyBsMika = 0;

    // Kalkulasi Sistem
    public $terjual = 0;
    public $tagihan = 0;

    // --- STATE EXTENSION (tunda settle) ---
    public bool $extensionGranted = false;
    public int $extensionCount = 0;

    // --- STATE STOP TITIPAN (cut off kios) ---
    public bool $showStopConfirm = false;
    public string $stopReason = '';

    // --- STATE WALK-IN CASH (penjualan cash ke pembeli random, bukan kios) ---
    public bool $isWalkInModalOpen = false;
    public int $walkInMika = 0;

    // --- STATE END TRIP ---
    public bool $isEndTripModalOpen = false;
    public string $endReason = '';
    public array $tripSummary = [
        'kios_visited' => 0,
        'total_mika_drop' => 0,
        'total_uang_diterima' => 0,
        'qty_carried' => 0,
        'total_mika_sisa' => 0,
    ];

    public function mount()
    {
        $this->trip = Trip::where('operator_id', auth()->id())
            ->whereNull('ended_at')
            ->first();

        if (!$this->trip) {
            return redirect()->route('operator.dashboard');
        }

        $this->starting_cluster_id = $this->trip->starting_cluster_id;
        $this->loadKiosks();
    }

    public function loadKiosks()
    {
        // Kios aktif di cluster trip. Tanpa starting_cluster = semua kios aktif (trip "Semua Kios").
        $query = Kiosk::where('is_active', true);

        // Multi-tenant: batasi ke kios milik owner operator (lewat cluster).
        // Guard null untuk backward-compat (data lama / operator tanpa owner_id).
        $ownerId = auth()->user()->owner_id;
        if ($ownerId !== null) {
            $query->whereHas('cluster', fn($q) => $q->where('owner_id', $ownerId));
        }

        if ($this->starting_cluster_id) {
            $query->where('cluster_id', $this->starting_cluster_id);
        }

        // Kios yang sudah dikunjungi pada trip ini (abaikan kunjungan yang dikoreksi)
        $this->visitedKioskIds = KioskVisit::active()
            ->where('trip_id', $this->trip->id)
            ->pluck('kiosk_id')
            ->all();

        $kiosks = $query->get();

        // Flag pintar per kios (dihitung sekaligus, hindari N+1)
        $this->computeKiosFlags($kiosks);

        // Operator terakhir yang mengunjungi tiap kios (1 query, hindari N+1)
        $kioskIds = $kiosks->pluck('id')->all();
        $lastOperatorData = KioskVisit::whereIn('kiosk_id', $kioskIds)
            ->whereNull('kiosk_visits.corrected_at')
            ->join('trips', 'kiosk_visits.trip_id', '=', 'trips.id')
            ->join('users', 'trips.operator_id', '=', 'users.id')
            ->groupBy('kiosk_visits.kiosk_id')
            ->selectRaw('kiosk_visits.kiosk_id, MAX(kiosk_visits.visited_at) as last_visited_at, '
                .'SUBSTRING_INDEX(GROUP_CONCAT(users.name ORDER BY kiosk_visits.visited_at DESC), ",", 1) as operator_name')
            ->get()
            ->keyBy('kiosk_id');

        $this->lastOperatorPerKiosk = $lastOperatorData->map(fn ($row) => [
            'name' => $row->operator_name,
            'date' => \Carbon\Carbon::parse($row->last_visited_at)->translatedFormat('d M Y'),
        ])->toArray();

        // Kios dengan titipan yang belum di-settle (satu query, hindari N+1)
        $this->pendingKioskIds = Delivery::whereIn('kiosk_id', $kiosks->pluck('id'))
            ->doesntHave('settlement')
            ->pluck('kiosk_id')
            ->unique()
            ->values()
            ->all();

        // Belum dikunjungi (false) di atas, sudah dikunjungi (true) di bawah.
        // Jika diurutkan berdasarkan jarak, urutkan berdasarkan status kunjungan dahulu, lalu jarak terdekat.
        $visited = $this->visitedKioskIds;
        if ($this->sortedByDistance && $this->userLat !== null && $this->userLng !== null) {
            $this->kiosks = $kiosks->sort(function ($a, $b) use ($visited) {
                $visitedA = in_array($a->id, $visited, true) ? 1 : 0;
                $visitedB = in_array($b->id, $visited, true) ? 1 : 0;
                if ($visitedA !== $visitedB) {
                    return $visitedA <=> $visitedB;
                }

                $distA = ($a->latitude === null || $a->longitude === null)
                    ? PHP_FLOAT_MAX
                    : $this->calculateDistance($this->userLat, $this->userLng, (float) $a->latitude, (float) $a->longitude);

                $distB = ($b->latitude === null || $b->longitude === null)
                    ? PHP_FLOAT_MAX
                    : $this->calculateDistance($this->userLat, $this->userLng, (float) $b->latitude, (float) $b->longitude);

                return $distA <=> $distB;
            })->values();
        } else {
            $this->kiosks = $kiosks
                ->sortBy(fn ($k) => in_array($k->id, $visited, true))
                ->values();
        }
    }

    /**
     * Hitung flag pintar untuk semua kios sekaligus (hindari N+1).
     * Flag: urgent, warning, new, fast_mover, slow_mover.
     */
    private function computeKiosFlags(Collection $kiosks): void
    {
        $kioskIds = $kiosks->pluck('id')->all();
        $this->kioskFlags = [];

        if (empty($kioskIds)) {
            return;
        }

        $today = now();
        $thirtyDaysAgo = $today->copy()->subDays(30)->toDateString();

        // Kunjungan terakhir per kios (MAX(visited_at)) — pakai index (kiosk_id, visited_at).
        $lastVisits = KioskVisit::active()
            ->whereIn('kiosk_id', $kioskIds)
            ->groupBy('kiosk_id')
            ->selectRaw('kiosk_id, MAX(visited_at) as last_visit')
            ->pluck('last_visit', 'kiosk_id');

        // Rata-rata hari sampai habis (settle) per kios, minimal 3 settlement.
        $avgDays = Settlement::query()
            ->join('deliveries', 'settlements.delivery_id', '=', 'deliveries.id')
            ->whereIn('deliveries.kiosk_id', $kioskIds)
            ->groupBy('deliveries.kiosk_id')
            ->havingRaw('COUNT(*) >= 3')
            ->selectRaw('deliveries.kiosk_id as kiosk_id, AVG(DATEDIFF(settlements.visit_date, deliveries.created_at)) as avg_days')
            ->pluck('avg_days', 'kiosk_id');

        foreach ($kiosks as $kiosk) {
            $flags = [];

            $lastVisit = $lastVisits[$kiosk->id] ?? null;
            // abs(): Carbon 3 diffInDays bertanda (tanggal lampau = negatif).
            $daysSinceVisit = $lastVisit
                ? (int) abs($today->diffInDays(\Illuminate\Support\Carbon::parse($lastVisit)))
                : 999;

            // URGENT — lewat target interval (default 10 hari).
            $urgentThreshold = $kiosk->target_visit_interval_days ?: 10;
            if ($daysSinceVisit > $urgentThreshold) {
                $flags[] = 'urgent';
            }

            // HAMPIR EXPIRED — lewat warning interval (kalau belum urgent).
            $warningThreshold = $kiosk->warning_visit_interval_days ?? null;
            if ($warningThreshold && $daysSinceVisit > $warningThreshold && ! in_array('urgent', $flags, true)) {
                $flags[] = 'warning';
            }

            // KIOS BARU — first_titip_date dalam 30 hari terakhir.
            if ($kiosk->first_titip_date && $kiosk->first_titip_date->toDateString() >= $thirtyDaysAgo) {
                $flags[] = 'new';
            }

            // FAST / SLOW MOVER — butuh threshold + minimal 3 data settle.
            $threshold = $kiosk->fast_mover_threshold_days ?? null;
            $avg = isset($avgDays[$kiosk->id]) ? (float) $avgDays[$kiosk->id] : null;
            if ($threshold && $avg !== null) {
                if ($avg < $threshold) {
                    $flags[] = 'fast_mover';
                } elseif ($avg > ($threshold * 2)) {
                    $flags[] = 'slow_mover';
                }
            }

            $this->kioskFlags[$kiosk->id] = $flags;
        }
    }

    public function sortByDistance($lat, $lng)
    {
        $this->userLat = (float) $lat;
        $this->userLng = (float) $lng;
        $this->sortedByDistance = true;

        $this->loadKiosks();
    }

    private function calculateDistance(float $latFrom, float $lngFrom, float $latTo, float $lngTo): float
    {
        $earthRadius = 6371000; // meter

        $latFromRad = deg2rad($latFrom);
        $lngFromRad = deg2rad($lngFrom);
        $latToRad = deg2rad($latTo);
        $lngToRad = deg2rad($lngTo);

        $latDelta = $latToRad - $latFromRad;
        $lngDelta = $lngToRad - $lngFromRad;

        $angle = 2 * asin(sqrt(
            pow(sin($latDelta / 2), 2) +
            cos($latFromRad) * cos($latToRad) * pow(sin($lngDelta / 2), 2)
        ));

        return $angle * $earthRadius;
    }

    // --- METODE TRANSAKSI MODAL ---
    public function openVisitModal($kioskId)
    {
        $this->selectedKiosk = Kiosk::find($kioskId);
        $this->isCashOnly = (bool) ($this->selectedKiosk?->is_cash_only);

        $this->pendingDelivery = Delivery::where('kiosk_id', $kioskId)
            ->doesntHave('settlement')
            ->latest('id')
            ->first();

        // Hitung berapa kali titipan ini sudah diperpanjang
        $this->extensionGranted = false;
        if ($this->pendingDelivery) {
            $this->extensionCount = KioskVisit::active()
                ->where('settled_delivery_id', $this->pendingDelivery->id)
                ->where('extension_granted', true)
                ->count();
        } else {
            $this->extensionCount = 0;
        }

        $this->resetVisitForm();

        // Reset state stop titipan tiap buka modal.
        $this->showStopConfirm = false;
        $this->stopReason = '';

        // Kios cash only tidak punya pilihan aksi — langsung ke form jual cash.
        $this->chosenAction = $this->isCashOnly ? 'cash' : null;

        $this->resetErrorBag();
        $this->isVisitModalOpen = true;
    }

    private function resetVisitForm(): void
    {
        $this->returnFresh = 0;
        $this->returnExpired = 0;
        $this->dropBaru = 0;
        $this->terjual = 0;
        $this->tagihan = 0;
        $this->uangDiterima = 0;
        $this->extraDropMode = 'cash';
        $this->turunkanDefault = false;
        $this->qtyDefaultBaru = 0;
        $this->alasanCheck = '';
        $this->sisaBiji = 0;
        $this->adaBsRedistribusi = false;
        $this->qtyBsMika = 0;
        $this->extensionGranted = false;
    }

    /**
     * Layar 1 modal: operator memilih aksi. Hanya mengatur section form yang
     * tampil + state extension — TIDAK menentukan visit_action yang tersimpan
     * (itu tetap auto-detect server-side di resolveVisitAction()).
     */
    public function chooseAction(string $action): void
    {
        $valid = $this->pendingDelivery
            ? ['tagih_titip', 'tagih', 'tunda']
            : ['titip', 'cek'];

        if (! in_array($action, $valid, true)) {
            return;
        }

        $this->chosenAction = $action;
        $this->extensionGranted = ($action === 'tunda');

        // Aksi tanpa titip baru: pastikan qty drop bersih.
        if (in_array($action, ['tagih', 'tunda', 'cek'], true)) {
            $this->dropBaru = 0;
        }
    }

    /** Kembali ke layar pilih aksi; bersihkan input agar tidak nyangkut antar aksi. */
    public function backToActionPicker(): void
    {
        $this->resetVisitForm();
        $this->chosenAction = null;
        $this->resetErrorBag();
    }

    public function closeVisitModal()
    {
        $this->isVisitModalOpen = false;
        $this->selectedKiosk = null;
        $this->pendingDelivery = null;
        $this->chosenAction = null;
        $this->showStopConfirm = false;
        $this->stopReason = '';
    }

    /**
     * Stop titipan (cut off) kios oleh operator dari modal kunjungan.
     * Kios di-nonaktifkan (is_active=false) + jejak stop, lalu hilang dari
     * daftar trip (loadKiosks() sudah filter is_active=true). Kunjungan
     * dicatat sebagai check_only beralasan 'stop_titipan' untuk jejak audit.
     */
    public function stopKios(): void
    {
        if (! $this->selectedKiosk) {
            $this->addError('stopReason', 'Kios tidak valid. Tutup form dan coba lagi.');
            return;
        }

        if (! array_key_exists($this->stopReason, Kiosk::STOP_REASONS)) {
            $this->addError('stopReason', 'Pilih alasan dulu');
            return;
        }

        // Masih ada titipan aktif (belum di-settle) → tidak boleh stop dulu.
        // Operator harus selesaikan tagihan / catat loss sebelum cut off.
        if ($this->pendingDelivery) {
            $this->addError('stopReason', 'Kios masih punya titipan aktif. Selesaikan tagihan dulu sebelum stop.');
            return;
        }

        DB::transaction(function () {
            $this->selectedKiosk->update([
                'is_active' => false,
                'stopped_at' => now(),
                'stop_reason' => $this->stopReason,
                'stopped_by' => 'operator',
            ]);

            // Catat sebagai kunjungan check_only dengan alasan stop (jejak audit).
            KioskVisit::create([
                'trip_id' => $this->trip->id,
                'kiosk_id' => $this->selectedKiosk->id,
                'visited_at' => now(),
                'visit_action' => 'check_only',
                'alasan_check' => 'stop_titipan',
                'extension_granted' => false,
            ]);
        });

        $name = $this->selectedKiosk->name;
        $this->closeVisitModal();
        $this->loadKiosks();
        session()->flash('visit_saved', "Kios {$name} dihentikan titipannya.");
    }

    public function updated($propertyName)
    {
        if (in_array($propertyName, ['returnFresh', 'returnExpired'])) {
            $this->hitungTagihan();
        }

        if ($propertyName === 'extensionGranted') {
            if ($this->extensionGranted) {
                $this->dropBaru = 0;
            }
        }
    }

    public function hitungTagihan()
    {
        if ($this->pendingDelivery) {
            $totalBijiDititip = $this->pendingDelivery->qty_delivered * self::BIJI_PER_MIKA;
            $this->terjual = $totalBijiDititip - ((int)$this->returnFresh + (int)$this->returnExpired);
            $this->terjual = max(0, $this->terjual);
            $this->tagihan = $this->terjual * self::HARGA_PER_BIJI;
            // Default uang diterima = tagihan penuh (operator bisa override manual)
            $this->uangDiterima = $this->tagihan;
        }
    }

    public function incrementDrop()
    {
        $this->dropBaru = max(0, $this->dropBaru + 1);
    }

    public function decrementDrop()
    {
        $this->dropBaru = max(0, $this->dropBaru - 1);
    }

    /**
     * Label aksi yang akan dilakukan, auto-detect dari kondisi form.
     */
    public function getVisitActionProperty(): string
    {
        return $this->resolveVisitAction();
    }

    private function resolveVisitAction(): string
    {
        // Kios cash only selalu penjualan cash langsung.
        if ($this->isCashOnly) {
            return 'cash_sale';
        }

        $drop = (int) $this->dropBaru;
        $hasPending = (bool) $this->pendingDelivery;

        if ($hasPending && $drop > 0) {
            return 'drop_and_settle';
        }
        if (!$hasPending && $drop > 0) {
            return 'drop_only';
        }
        if ($hasPending && $drop === 0) {
            return 'settle_only';
        }

        return 'check_only';
    }

    /**
     * 1 jenis dodol, 1 varian aktif. Operator tidak memilih.
     */
    private function resolveActiveVariant(): ProductVariant
    {
        $variant = ProductVariant::where('is_active', true)->first();

        if (!$variant) {
            throw new \RuntimeException('Tidak ada varian produk aktif.');
        }

        return $variant;
    }

    public function saveVisit()
    {
        if ($this->extensionGranted) {
            $this->dropBaru = 0;
        }

        $this->validate([
            'returnFresh' => 'nullable|integer|min:0',
            'returnExpired' => 'nullable|integer|min:0',
            'dropBaru' => 'nullable|integer|min:0',
            'uangDiterima' => 'nullable|integer|min:0',
        ]);

        $this->resetErrorBag('general');

        if (!$this->selectedKiosk) {
            $this->addError('general', 'Kios tidak valid. Tutup form dan coba lagi.');
            return;
        }

        // Guard idempotensi: submit ganda (koneksi lambat, request retry) tidak
        // boleh membuat kunjungan duplikat. Kunjungan ulang yang sah ke kios
        // yang sama tetap bisa setelah jeda singkat ini.
        $alreadySaved = KioskVisit::where('trip_id', $this->trip->id)
            ->where('kiosk_id', $this->selectedKiosk->id)
            ->where('visited_at', '>=', now()->subSeconds(10))
            ->exists();

        if ($alreadySaved) {
            $this->loadKiosks();
            $this->closeVisitModal();
            session()->flash('visit_saved', 'Kunjungan sudah tersimpan.');
            return;
        }

        $message = $this->persistVisitFromState();
        if ($message === null) {
            return;
        }

        $this->loadKiosks();
        $this->extraDropMode = 'cash';
        $this->closeVisitModal();
        session()->flash('visit_saved', $message);
    }

    /**
     * Koreksi angka kunjungan TERAKHIR ke sebuah kios pada trip aktif (reversal).
     * Prinsip: record finansial visit lama DIHAPUS (angka lama hilang dari hitungan),
     * baris kiosk_visits lama DISIMPAN + ditandai corrected_at (audit trail), lalu
     * angka baru ditulis ulang lewat persistVisitFromState() (satu sumber kebenaran).
     *
     * Hanya ANGKA yang dikoreksi (drop mika, retur fresh/expired, uang diterima);
     * bukan ganti kios / aksi / BS / default kios.
     */
    public function correctVisit(int $visitId, int $dropBaru, int $returnFresh, int $returnExpired, int $uangDiterima): void
    {
        $this->resetErrorBag('correction');

        $visit = KioskVisit::with('kiosk')->find($visitId);

        // --- Validasi batasan koreksi ---
        if (! $visit || $visit->trip_id !== $this->trip->id) {
            $this->addError('correction', 'Kunjungan tidak ditemukan pada trip ini.');
            return;
        }
        if ($this->trip->ended_at !== null) {
            $this->addError('correction', 'Trip sudah diakhiri, koreksi tidak bisa dilakukan.');
            return;
        }
        if ($visit->corrected_at !== null) {
            $this->addError('correction', 'Kunjungan ini sudah dikoreksi sebelumnya.');
            return;
        }
        if ($visit->kiosk && $visit->kiosk->name === Kiosk::WALKIN_SENTINEL_NAME) {
            $this->addError('correction', 'Penjualan walk-in tidak bisa dikoreksi.');
            return;
        }
        if ($visit->changed_default) {
            $this->addError('correction', 'Kunjungan yang mengubah default kios tidak bisa dikoreksi.');
            return;
        }

        // Hanya visit TERAKHIR (tidak ada kunjungan aktif lebih baru ke kios yang sama).
        $adaLebihBaru = KioskVisit::active()
            ->where('trip_id', $this->trip->id)
            ->where('kiosk_id', $visit->kiosk_id)
            ->where('id', '!=', $visit->id)
            ->where('visited_at', '>=', $visit->visited_at)
            ->exists();
        if ($adaLebihBaru) {
            $this->addError('correction', 'Hanya kunjungan terakhir ke kios ini yang bisa dikoreksi.');
            return;
        }

        // Determinisme: visit yang membuat delivery harus punya linkage lengkap
        // (data sebelum fitur koreksi tidak punya kiosk_visit_id → tidak bisa direversal).
        if ($visit->new_delivery_id !== null
            && ! Delivery::where('kiosk_visit_id', $visit->id)->whereKey($visit->new_delivery_id)->exists()) {
            $this->addError('correction', 'Kunjungan ini dibuat sebelum fitur koreksi dan tidak bisa dikoreksi.');
            return;
        }

        // --- Siapkan state untuk penyimpanan ulang ---
        $this->selectedKiosk = $visit->kiosk()->first();
        $this->isCashOnly = (bool) ($this->selectedKiosk?->is_cash_only);
        // Titipan lama yang akan di-settle ulang = yang dulu di-settle visit ini
        // (settlement-nya dihapus di transaksi → titipan aktif lagi).
        $this->pendingDelivery = $visit->settled_delivery_id
            ? Delivery::find($visit->settled_delivery_id)
            : null;

        $this->dropBaru = $dropBaru;
        $this->returnFresh = $returnFresh;
        $this->returnExpired = $returnExpired;
        $this->uangDiterima = $uangDiterima;
        // Koreksi hanya angka — flag perilaku lain dinetralkan.
        $this->extensionGranted = false;
        $this->turunkanDefault = false;
        $this->qtyDefaultBaru = 0;
        $this->adaBsRedistribusi = false;
        $this->qtyBsMika = 0;
        $this->extraDropMode = 'cash';
        $this->alasanCheck = '';
        $this->sisaBiji = 0;

        $this->validate([
            'returnFresh' => 'nullable|integer|min:0',
            'returnExpired' => 'nullable|integer|min:0',
            'dropBaru' => 'nullable|integer|min:0',
            'uangDiterima' => 'nullable|integer|min:0',
        ]);

        try {
            DB::transaction(function () use ($visit) {
                // a. Hapus settlement milik delivery yang dibuat visit ini.
                $linkedDeliveryIds = Delivery::where('kiosk_visit_id', $visit->id)->pluck('id');
                if ($linkedDeliveryIds->isNotEmpty()) {
                    Settlement::whereIn('delivery_id', $linkedDeliveryIds)->delete();
                }

                // b. Hapus settlement penagihan titipan lama → titipan lama aktif lagi.
                if ($visit->settled_delivery_id) {
                    Settlement::where('delivery_id', $visit->settled_delivery_id)->delete();
                }

                // BS: kembalikan counter trip sebelum delivery BS dihapus.
                $bsQty = (int) Delivery::where('kiosk_visit_id', $visit->id)
                    ->where('delivery_type', 'bs_redistribution')
                    ->sum('qty_delivered');
                if ($bsQty > 0) {
                    $this->trip->decrement('qty_bs_redistributed', $bsQty);
                }

                // c. Hapus semua delivery yang dibuat visit ini (settlement-nya sudah dihapus).
                Delivery::where('kiosk_visit_id', $visit->id)->delete();

                // d. Tandai visit lama sebagai dikoreksi (audit trail, baris disimpan).
                $visit->update(['corrected_at' => now()]);

                // e. Tulis ulang dengan angka baru — visit baru menaut ke visit lama.
                $message = $this->persistVisitFromState($visit->id);
                if ($message === null) {
                    // Error sudah di-addError oleh persist → rollback semuanya.
                    throw new \RuntimeException('Penyimpanan ulang gagal.');
                }
            });
        } catch (\Throwable $e) {
            if ($this->getErrorBag()->isNotEmpty()) {
                return; // error spesifik sudah ditambahkan
            }
            $this->addError('correction', 'Gagal mengoreksi kunjungan. Coba lagi.');
            return;
        }

        $this->loadKiosks();
        $this->extraDropMode = 'cash';
        $this->closeVisitModal();
        session()->flash('visit_saved', 'Kunjungan berhasil dikoreksi.');
    }

    /**
     * Inti penyimpanan kunjungan dari state komponen ($selectedKiosk, $pendingDelivery,
     * dropBaru/returnFresh/returnExpired/uangDiterima + flag). SATU sumber kebenaran:
     * dipakai saveVisit() DAN correctVisit(). Mengembalikan pesan sukses, atau null bila
     * gagal (error sudah di-addError). TIDAK memanggil loadKiosks/flash/closeModal —
     * itu tugas pemanggil. Semua delivery yang dibuat ditaut ke KioskVisit lewat
     * kiosk_visit_id (linkage deterministik untuk reversal koreksi).
     *
     * @param int|null $correctionOfVisitId  Bila ini hasil koreksi: id visit yang dikoreksi.
     */
    private function persistVisitFromState(?int $correctionOfVisitId = null): ?string
    {
        $drop = (int) $this->dropBaru;
        $fresh = (int) $this->returnFresh;
        $expired = (int) $this->returnExpired;
        $hasPending = (bool) $this->pendingDelivery;
        $action = $this->resolveVisitAction();
        $isSettleAction = in_array($action, ['drop_and_settle', 'settle_only'], true);
        $isDrop = in_array($action, ['drop_and_settle', 'drop_only'], true);

        // === SKENARIO KIOS CASH ONLY ===
        // Setiap kunjungan = penjualan cash langsung lunas, tanpa konsinyasi.
        if ($this->isCashOnly) {
            if ($drop <= 0) {
                $this->addError('general', 'Jumlah mika harus lebih dari 0 untuk penjualan cash.');
                return null;
            }

            try {
                $variant = $this->resolveActiveVariant();
            } catch (\RuntimeException $e) {
                $this->addError('general', $e->getMessage());
                return null;
            }

            try {
                DB::transaction(function () use ($drop, $variant, $correctionOfVisitId) {
                    $delivery = Delivery::create([
                        'kiosk_id' => $this->selectedKiosk->id,
                        'trip_id' => $this->trip->id,
                        'product_variant_id' => $variant->id,
                        'procurement_batch_id' => null,
                        'source_type' => 'new_procurement',
                        'delivery_type' => 'cash_sale',
                        'qty_delivered' => $drop,
                        'unit_price' => $variant->sale_price_per_pack,
                        'cost_snapshot' => null,
                    ]);

                    $totalBiji = $drop * self::BIJI_PER_MIKA;
                    $amountDue = $totalBiji * self::HARGA_PER_BIJI;

                    Settlement::create([
                        'delivery_id' => $delivery->id,
                        'visit_date' => today(),
                        'qty_sold' => $totalBiji,
                        'qty_returned_fresh' => 0,
                        'qty_returned_expired' => 0,
                        'amount_due' => $amountDue,
                        'amount_paid' => $amountDue, // langsung lunas
                    ]);

                    $visit = KioskVisit::create([
                        'trip_id' => $this->trip->id,
                        'kiosk_id' => $this->selectedKiosk->id,
                        'visited_at' => now(),
                        'visit_action' => 'cash_sale',
                        'new_delivery_id' => $delivery->id,
                        'settled_delivery_id' => $delivery->id,
                        'extension_granted' => false,
                        'correction_of_visit_id' => $correctionOfVisitId,
                    ]);

                    $delivery->update(['kiosk_visit_id' => $visit->id]);
                });
            } catch (\Throwable $e) {
                $this->addError('general', 'Gagal menyimpan. Coba lagi.');
                return null;
            }

            return 'Kunjungan cash berhasil disimpan.';
        }

        // Deteksi drop melebihi default_qty_mika. Operator memilih perlakuan kelebihan:
        // - 'cash'       : bagian default konsinyasi, sisanya cash langsung (default).
        // - 'konsinyasi' : semua konsinyasi penuh + naikkan default kios ke jumlah drop.
        $defaultQty = (int) ($this->selectedKiosk->default_qty_mika ?? 0);
        $extraQty = max(0, $drop - $defaultQty);
        $hasCashExtra = $isDrop && $extraQty > 0 && $defaultQty > 0
            && $this->extraDropMode === 'cash';
        $isKonsinyasiFull = $isDrop && $extraQty > 0 && $defaultQty > 0
            && $this->extraDropMode === 'konsinyasi';

        // SKENARIO 7: mika BS redistribusi yang ikut di-drop (delivery terpisah,
        // titipan konsinyasi biasa — TIDAK di-settle saat ini, dibayar saat terjual).
        $bsMika = ($isDrop && $this->adaBsRedistribusi && (int) $this->qtyBsMika > 0)
            ? (int) $this->qtyBsMika
            : 0;

        // Extension hanya berlaku untuk aksi yang seharusnya settle + ada titipan lama.
        // Kalau granted: settle DITUNDA (tidak buat row settlements), drop tetap jalan.
        $extension = $this->extensionGranted && $hasPending && $isSettleAction;
        $createSettlement = $isSettleAction && !$extension;

        // Visit yang mengubah default_qty_mika (turunkan default / konsinyasi penuh)
        // ditandai → DILARANG dikoreksi (nilai default lama tak tersimpan untuk revert).
        $willLowerDefault = $isSettleAction && $this->turunkanDefault
            && $this->qtyDefaultBaru > 0
            && $this->qtyDefaultBaru < (int) $this->selectedKiosk->default_qty_mika;
        $changedDefault = $willLowerDefault || $isKonsinyasiFull;

        // --- Hitung ulang terjual & tagihan dari server (jangan percaya client),
        //     TANPA menimpa uangDiterima yang diinput operator ---
        if ($createSettlement) {
            $totalBiji = (int) $this->pendingDelivery->qty_delivered * self::BIJI_PER_MIKA;

            if (($fresh + $expired) > $totalBiji) {
                $this->addError('general', 'Total retur melebihi jumlah titipan sebelumnya.');
                return null;
            }

            $this->terjual = max(0, $totalBiji - $fresh - $expired);
            $this->tagihan = $this->terjual * self::HARGA_PER_BIJI;
        }

        if ((int) $this->uangDiterima < 0) {
            $this->addError('uangDiterima', 'Uang diterima tidak boleh negatif.');
            return null;
        }

        // --- Resolve varian aktif SEBELUM transaksi (tidak ada block stok batch) ---
        try {
            $variant = $isDrop ? $this->resolveActiveVariant() : null;
        } catch (\RuntimeException $e) {
            $this->addError('general', $e->getMessage());
            return null;
        }

        try {
            DB::transaction(function () use ($action, $drop, $fresh, $expired, $isSettleAction, $createSettlement, $extension, $isDrop, $variant, $extraQty, $hasCashExtra, $isKonsinyasiFull, $bsMika, $willLowerDefault, $changedDefault, $correctionOfVisitId) {
                $newDeliveryId = null;
                $settledDeliveryId = null;
                $createdDeliveryIds = [];

                // Aksi settle-type selalu menandai delivery lama (agar extension count
                // & jejak kunjungan terhitung), meski settlement-nya ditunda.
                if ($isSettleAction) {
                    $settledDeliveryId = $this->pendingDelivery->id;
                }

                // 1. Settle titipan lama (dilewati kalau extension/tunda bayar)
                if ($createSettlement) {
                    Settlement::create([
                        'delivery_id' => $this->pendingDelivery->id,
                        'visit_date' => today(),
                        'qty_sold' => (int) $this->terjual,
                        'qty_returned_fresh' => $fresh,
                        'qty_returned_expired' => $expired,
                        'amount_due' => (int) $this->tagihan,
                        'amount_paid' => (int) $this->uangDiterima,
                        // status & paid_at di-set otomatis oleh SettlementObserver
                    ]);
                }

                // SKENARIO 4: turunkan default qty kios saat settle (harus lebih kecil
                // dari default saat ini agar tidak bentrok dengan logika naik-default).
                if ($willLowerDefault) {
                    $this->selectedKiosk->update(['default_qty_mika' => $this->qtyDefaultBaru]);
                }

                // 2. Drop titipan baru (new_procurement, tanpa link batch — operasional bebas)
                if ($isDrop) {
                    // Kalau drop melebihi default: bagian default = konsinyasi, sisanya = cash.
                    $konsinyasiQty = $hasCashExtra ? ($drop - $extraQty) : $drop;

                    $newDelivery = Delivery::create([
                        'kiosk_id' => $this->selectedKiosk->id,
                        'trip_id' => $this->trip->id,
                        'product_variant_id' => $variant->id,
                        'procurement_batch_id' => null,
                        'source_type' => 'new_procurement',
                        'delivery_type' => 'consignment',
                        'qty_delivered' => $konsinyasiQty,
                        'unit_price' => $variant->sale_price_per_pack,
                        'cost_snapshot' => null,
                    ]);
                    $newDeliveryId = $newDelivery->id;
                    $createdDeliveryIds[] = $newDelivery->id;

                    // Konsinyasi penuh: kelebihan tidak dijual cash, melainkan dinaikkan
                    // jadi default baru kios (semua mika di-drop sebagai konsinyasi).
                    if ($isKonsinyasiFull) {
                        $this->selectedKiosk->update(['default_qty_mika' => $drop]);
                    }

                    // Kelebihan di atas default = delivery cash terpisah, langsung lunas.
                    if ($hasCashExtra) {
                        $cashDelivery = Delivery::create([
                            'kiosk_id' => $this->selectedKiosk->id,
                            'trip_id' => $this->trip->id,
                            'product_variant_id' => $variant->id,
                            'procurement_batch_id' => null,
                            'source_type' => 'new_procurement',
                            'delivery_type' => 'cash_sale',
                            'qty_delivered' => $extraQty,
                            'unit_price' => $variant->sale_price_per_pack,
                            'cost_snapshot' => null,
                        ]);
                        $createdDeliveryIds[] = $cashDelivery->id;

                        $totalBijiCash = $extraQty * self::BIJI_PER_MIKA;
                        $amountDueCash = $totalBijiCash * self::HARGA_PER_BIJI;

                        Settlement::create([
                            'delivery_id' => $cashDelivery->id,
                            'visit_date' => today(),
                            'qty_sold' => $totalBijiCash,
                            'qty_returned_fresh' => 0,
                            'qty_returned_expired' => 0,
                            'amount_due' => $amountDueCash,
                            'amount_paid' => $amountDueCash, // langsung lunas
                        ]);
                    }

                    // SKENARIO 7: mika BS redistribusi = delivery terpisah, titipan
                    // konsinyasi biasa (HPP 0 karena loss sudah dihitung di kios asal).
                    // TIDAK di-settle sekarang — dibayar nanti saat terjual, seperti titipan normal.
                    if ($bsMika > 0) {
                        $bsDelivery = Delivery::create([
                            'kiosk_id' => $this->selectedKiosk->id,
                            'trip_id' => $this->trip->id,
                            'product_variant_id' => $variant->id,
                            'procurement_batch_id' => null,
                            'source_type' => 'new_procurement',
                            'delivery_type' => 'bs_redistribution',
                            'qty_delivered' => $bsMika,
                            'unit_price' => $variant->sale_price_per_pack,
                            'cost_snapshot' => 0,
                        ]);
                        $createdDeliveryIds[] = $bsDelivery->id;

                        // qty BS tidak berasal dari qty_carried → dicatat terpisah.
                        $this->trip->increment('qty_bs_redistributed', $bsMika);
                    }
                }

                // 3. Catat kunjungan (alasan & sisa biji hanya untuk check_only)
                $visit = KioskVisit::create([
                    'trip_id' => $this->trip->id,
                    'kiosk_id' => $this->selectedKiosk->id,
                    'visited_at' => now(),
                    'visit_action' => $action,
                    'alasan_check' => $action === 'check_only' ? ($this->alasanCheck ?: null) : null,
                    'sisa_biji' => $action === 'check_only' && $this->sisaBiji > 0 ? $this->sisaBiji : null,
                    'new_delivery_id' => $newDeliveryId,
                    'settled_delivery_id' => $settledDeliveryId,
                    'extension_granted' => $extension,
                    'changed_default' => $changedDefault,
                    'correction_of_visit_id' => $correctionOfVisitId,
                ]);

                // Linkage deterministik: semua delivery yang dibuat visit ini → visit.id.
                if (! empty($createdDeliveryIds)) {
                    Delivery::whereIn('id', $createdDeliveryIds)->update(['kiosk_visit_id' => $visit->id]);
                }
            });
        } catch (\Throwable $e) {
            // DB::transaction() auto-rollback; jangan rollback manual
            $this->addError('general', 'Gagal menyimpan. Coba lagi.');
            return null;
        }

        return $isKonsinyasiFull
            ? "Kunjungan disimpan. Default kios diperbarui ke {$drop} mika."
            : 'Kunjungan berhasil disimpan.';
    }

    // --- WALK-IN CASH FLOW ---
    public function openWalkInModal(): void
    {
        $this->walkInMika = 0;
        $this->resetErrorBag(['walkInMika', 'general']);
        $this->isWalkInModalOpen = true;
    }

    public function closeWalkInModal(): void
    {
        $this->isWalkInModalOpen = false;
        $this->walkInMika = 0;
    }

    /**
     * Catat penjualan cash walk-in (pembeli random, bukan kios terdaftar).
     * Disimpan ke kios sentinel tersembunyi milik owner trip ini, mengikuti
     * persis pola cash-only saveVisit(): Delivery cash_sale + Settlement lunas
     * + KioskVisit. Omset otomatis masuk komisi operator karena dihitung dari
     * settled_delivery_id per-trip (lihat Trip::omset_val).
     */
    public function saveWalkInCash(): void
    {
        $this->validate([
            'walkInMika' => 'required|integer|min:1',
        ], [
            'walkInMika.required' => 'Isi jumlah mika dulu.',
            'walkInMika.min' => 'Jumlah mika minimal 1.',
        ]);

        $this->resetErrorBag('general');

        $sentinel = Kiosk::walkInSentinelFor($this->trip->owner_id);

        // Guard idempotensi: submit ganda (koneksi lambat / retry) tidak boleh
        // membuat penjualan walk-in duplikat dalam jeda singkat.
        $alreadySaved = KioskVisit::where('trip_id', $this->trip->id)
            ->where('kiosk_id', $sentinel->id)
            ->where('visited_at', '>=', now()->subSeconds(10))
            ->exists();

        if ($alreadySaved) {
            $this->loadKiosks();
            $this->closeWalkInModal();
            session()->flash('visit_saved', 'Penjualan cash sudah tercatat.');
            return;
        }

        try {
            $variant = $this->resolveActiveVariant();
        } catch (\RuntimeException $e) {
            $this->addError('general', $e->getMessage());
            return;
        }

        $mika = (int) $this->walkInMika;

        try {
            DB::transaction(function () use ($mika, $variant, $sentinel) {
                $delivery = Delivery::create([
                    'kiosk_id' => $sentinel->id,
                    'trip_id' => $this->trip->id,
                    'product_variant_id' => $variant->id,
                    'procurement_batch_id' => null,
                    'source_type' => 'new_procurement',
                    'delivery_type' => 'cash_sale',
                    'qty_delivered' => $mika,
                    'unit_price' => $variant->sale_price_per_pack,
                    'cost_snapshot' => null,
                ]);

                $totalBiji = $mika * self::BIJI_PER_MIKA;
                $amountDue = $totalBiji * self::HARGA_PER_BIJI;

                Settlement::create([
                    'delivery_id' => $delivery->id,
                    'visit_date' => today(),
                    'qty_sold' => $totalBiji,
                    'qty_returned_fresh' => 0,
                    'qty_returned_expired' => 0,
                    'amount_due' => $amountDue,
                    'amount_paid' => $amountDue, // langsung lunas
                ]);

                $visit = KioskVisit::create([
                    'trip_id' => $this->trip->id,
                    'kiosk_id' => $sentinel->id,
                    'visited_at' => now(),
                    'visit_action' => 'cash_sale',
                    'new_delivery_id' => $delivery->id,
                    'settled_delivery_id' => $delivery->id,
                    'extension_granted' => false,
                ]);

                $delivery->update(['kiosk_visit_id' => $visit->id]);
            });
        } catch (\Throwable $e) {
            $this->addError('general', 'Gagal menyimpan. Coba lagi.');
            return;
        }

        $this->loadKiosks();
        $this->closeWalkInModal();
        session()->flash('visit_saved', 'Penjualan cash berhasil dicatat.');
    }

    // --- END TRIP FLOW ---
    public function openEndTripModal()
    {
        $totalDrop = (int) Delivery::where('trip_id', $this->trip->id)->sum('qty_delivered');
        $qtyCarried = (int) ($this->trip->qty_carried_total ?? 0);
        $totalMikaCash = (int) Delivery::where('trip_id', $this->trip->id)
            ->where('delivery_type', 'cash_sale')
            ->sum('qty_delivered');
        $totalAmountCash = $totalMikaCash * self::BIJI_PER_MIKA * self::HARGA_PER_BIJI;

        $this->tripSummary = [
            'kios_visited' => KioskVisit::active()->where('trip_id', $this->trip->id)->count(),
            'kios_lama' => (int) $this->trip->kios_lama_count,
            'kios_baru' => (int) $this->trip->kios_baru_count,
            'qty_carried' => $qtyCarried,
            'total_mika_drop' => $totalDrop,
            'total_mika_sisa' => $qtyCarried - $totalDrop,
            
            'mika_terjual' => (float) $this->trip->mika_terjual,
            'mika_kios_baru' => (float) $this->trip->mika_kios_baru,
            'total_mika_cash' => $totalMikaCash,
            'total_amount_cash' => $totalAmountCash,
            'total_uang_diterima' => (int) $this->trip->omset_val,
            'hpp_estimasi' => (int) $this->trip->hpp_estimasi,
            'untung_kotor' => (int) $this->trip->untung_kotor,
            'komisi_reguler' => (int) $this->trip->komisi_reguler,
            'komisi_kios_baru' => (int) $this->trip->komisi_kios_baru,
            'komisi_rian' => (int) $this->trip->komisi_rian,
            'untung_bersih_owner' => (int) $this->trip->untung_bersih_owner,
        ];

        $this->endReason = '';
        $this->resetErrorBag('endReason');
        $this->isEndTripModalOpen = true;
    }

    public function closeEndTripModal()
    {
        $this->isEndTripModalOpen = false;
        $this->endReason = '';
    }

    public function confirmEndTrip()
    {
        // Validasi: alasan wajib + harus salah satu nilai valid
        if (!in_array($this->endReason, self::VALID_END_REASONS, true)) {
            $this->addError('endReason', 'Pilih alasan mengakhiri trip.');
            return;
        }

        // Trip harus masih aktif
        if (!$this->trip || $this->trip->ended_at !== null) {
            $this->addError('endReason', 'Trip sudah diakhiri.');
            return;
        }

        DB::transaction(function () {
            $this->trip->update([
                'ended_at' => now(),
                'ended_reason' => $this->endReason,
            ]);

            // Save commission record to the database using the new formula
            $omset = $this->trip->omset_val;
            $komisi = $this->trip->komisi_rian;

            $cashCollectedReported = $omset;
            $marginRateAssumed = 0;

            if ($omset > 0) {
                $marginRateAssumed = $komisi / ($omset * 0.2000);
            }

            // Fallback if omset is 0 or margin rate would overflow decimal(5,4)
            if ($omset == 0 || $marginRateAssumed > 9.0) {
                $cashCollectedReported = $komisi / 0.2000;
                $marginRateAssumed = 1.0000;
            }

            $owner = $this->trip->owner;
            $komisiPerMika = $owner ? $owner->getKomisiPerMikaValue() : 500;
            $komisiKiosBaru = $owner ? $owner->getKomisiKiosBaruPerMikaValue() : 1000;

            \App\Models\Commission::create([
                'trip_id' => $this->trip->id,
                'operator_id' => $this->trip->operator_id,
                'cash_collected_reported' => $cashCollectedReported,
                'margin_rate_assumed' => $marginRateAssumed,
                'commission_rate' => 0.2000,
                'status' => 'paid',
                'paid_at' => now(),
                'notes' => sprintf('Komisi Rian: reguler (mika terjual x %d) + kios baru (mika kios baru x %d)', $komisiPerMika, $komisiKiosBaru),
            ]);
        });

        session()->flash('trip_ended', 'Trip berhasil diakhiri.');

        return redirect()->route('operator.dashboard');
    }

    public function render()
    {
        return view('livewire.operator.active-trip');
    }
}
