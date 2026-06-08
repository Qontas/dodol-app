<?php

namespace App\Livewire\Operator;

use Livewire\Component;
use App\Models\Trip;
use App\Models\Kiosk;
use App\Models\Delivery;
use App\Models\Settlement;
use App\Models\KioskVisit;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;

#[Layout('layouts.operator')]
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

    // --- STATE GEO/NEAREST NEIGHBOR ---
    public bool $sortedByDistance = false;
    public ?float $userLat = null;
    public ?float $userLng = null;

    // --- STATE TRANSAKSI KUNJUNGAN ---
    public $isVisitModalOpen = false;
    public $selectedKiosk = null;
    public $pendingDelivery = null;

    // Kios cash only: setiap drop langsung bayar cash (di-set dari kios terpilih)
    public bool $isCashOnly = false;

    // Input Form dari Rian
    public $returnFresh = 0;
    public $returnExpired = 0;
    public $dropBaru = 0;
    public $uangDiterima = 0;

    // Kalkulasi Sistem
    public $terjual = 0;
    public $tagihan = 0;

    // --- STATE EXTENSION (tunda settle) ---
    public bool $extensionGranted = false;
    public int $extensionCount = 0;

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

        // Kios yang sudah dikunjungi pada trip ini
        $this->visitedKioskIds = KioskVisit::where('trip_id', $this->trip->id)
            ->pluck('kiosk_id')
            ->all();

        $kiosks = $query->get();

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
            $this->extensionCount = KioskVisit::where('settled_delivery_id', $this->pendingDelivery->id)
                ->where('extension_granted', true)
                ->count();
        } else {
            $this->extensionCount = 0;
        }

        // Reset form
        $this->returnFresh = 0;
        $this->returnExpired = 0;
        $this->dropBaru = 0;
        $this->terjual = 0;
        $this->tagihan = 0;
        $this->uangDiterima = 0;

        $this->resetErrorBag();
        $this->isVisitModalOpen = true;
    }

    public function closeVisitModal()
    {
        $this->isVisitModalOpen = false;
        $this->selectedKiosk = null;
        $this->pendingDelivery = null;
    }

    public function updated($propertyName)
    {
        if (in_array($propertyName, ['returnFresh', 'returnExpired'])) {
            $this->hitungTagihan();
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
                return;
            }

            try {
                $variant = $this->resolveActiveVariant();
            } catch (\RuntimeException $e) {
                $this->addError('general', $e->getMessage());
                return;
            }

            try {
                DB::transaction(function () use ($drop, $variant) {
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

                    KioskVisit::create([
                        'trip_id' => $this->trip->id,
                        'kiosk_id' => $this->selectedKiosk->id,
                        'visited_at' => now(),
                        'visit_action' => 'cash_sale',
                        'new_delivery_id' => $delivery->id,
                        'settled_delivery_id' => $delivery->id,
                        'extension_granted' => false,
                    ]);
                });
            } catch (\Throwable $e) {
                $this->addError('general', 'Gagal menyimpan. Coba lagi.');
                return;
            }

            $this->loadKiosks();
            $this->closeVisitModal();
            session()->flash('visit_saved', 'Kunjungan cash berhasil disimpan.');
            return;
        }

        // Deteksi drop extra cash: kelebihan di atas default_qty_mika bayar cash langsung.
        $defaultQty = (int) ($this->selectedKiosk->default_qty_mika ?? 0);
        $extraQty = max(0, $drop - $defaultQty);
        $hasCashExtra = $isDrop && $extraQty > 0 && $defaultQty > 0;

        // Extension hanya berlaku untuk aksi yang seharusnya settle + ada titipan lama.
        // Kalau granted: settle DITUNDA (tidak buat row settlements), drop tetap jalan.
        $extension = $this->extensionGranted && $hasPending && $isSettleAction;
        $createSettlement = $isSettleAction && !$extension;

        // --- Hitung ulang terjual & tagihan dari server (jangan percaya client),
        //     TANPA menimpa uangDiterima yang diinput operator ---
        if ($createSettlement) {
            $totalBiji = (int) $this->pendingDelivery->qty_delivered * self::BIJI_PER_MIKA;

            if (($fresh + $expired) > $totalBiji) {
                $this->addError('general', 'Total retur melebihi jumlah titipan sebelumnya.');
                return;
            }

            $this->terjual = max(0, $totalBiji - $fresh - $expired);
            $this->tagihan = $this->terjual * self::HARGA_PER_BIJI;
        }

        if ((int) $this->uangDiterima < 0) {
            $this->addError('uangDiterima', 'Uang diterima tidak boleh negatif.');
            return;
        }

        // --- Resolve varian aktif SEBELUM transaksi (tidak ada block stok batch) ---
        try {
            $variant = $isDrop ? $this->resolveActiveVariant() : null;
        } catch (\RuntimeException $e) {
            $this->addError('general', $e->getMessage());
            return;
        }

        try {
            DB::transaction(function () use ($action, $drop, $fresh, $expired, $isSettleAction, $createSettlement, $extension, $isDrop, $variant, $extraQty, $hasCashExtra) {
                $newDeliveryId = null;
                $settledDeliveryId = null;

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
                }

                // 3. Catat kunjungan
                KioskVisit::create([
                    'trip_id' => $this->trip->id,
                    'kiosk_id' => $this->selectedKiosk->id,
                    'visited_at' => now(),
                    'visit_action' => $action,
                    'new_delivery_id' => $newDeliveryId,
                    'settled_delivery_id' => $settledDeliveryId,
                    'extension_granted' => $extension,
                ]);
            });
        } catch (\Throwable $e) {
            // DB::transaction() auto-rollback; jangan rollback manual
            $this->addError('general', 'Gagal menyimpan. Coba lagi.');
            return;
        }

        // 4. Refresh daftar + reset form + tutup modal
        $this->loadKiosks();
        $this->closeVisitModal();
        session()->flash('visit_saved', 'Kunjungan berhasil disimpan.');
    }

    // --- END TRIP FLOW ---
    public function openEndTripModal()
    {
        $totalDrop = (int) Delivery::where('trip_id', $this->trip->id)->sum('qty_delivered');
        $qtyCarried = (int) ($this->trip->qty_carried_total ?? 0);

        $this->tripSummary = [
            'kios_visited' => KioskVisit::where('trip_id', $this->trip->id)->count(),
            'kios_lama' => (int) $this->trip->kios_lama_count,
            'kios_baru' => (int) $this->trip->kios_baru_count,
            'qty_carried' => $qtyCarried,
            'total_mika_drop' => $totalDrop,
            'total_mika_sisa' => $qtyCarried - $totalDrop,
            
            'mika_terjual' => (float) $this->trip->mika_terjual,
            'mika_kios_baru' => (float) $this->trip->mika_kios_baru,
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

            \App\Models\Commission::create([
                'trip_id' => $this->trip->id,
                'operator_id' => $this->trip->operator_id,
                'cash_collected_reported' => $cashCollectedReported,
                'margin_rate_assumed' => $marginRateAssumed,
                'commission_rate' => 0.2000,
                'status' => 'paid',
                'paid_at' => now(),
                'notes' => 'Komisi Rian: reguler (mika terjual x 500) + kios baru (mika kios baru x 1000)',
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
