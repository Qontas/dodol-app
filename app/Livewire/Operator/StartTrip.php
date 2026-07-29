<?php

namespace App\Livewire\Operator;

use App\Models\Cluster;
use App\Models\Kiosk;
use App\Models\KioskVisit;
use App\Models\Trip;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.operator')]
class StartTrip extends Component
{
    public ?int $selectedClusterId = null;
    public int $qtyCarried = 0;

    // Trip Bebas: antar lintas cluster (semua kios aktif owner), tanpa pilih cluster.
    public bool $tripBebas = false;

    // Pesan saat operator menekan "Mulai Trip" padahal trip lain masih berjalan.
    // JANGAN diam-diam redirect (lihat catatan besar di startTrip()).
    public string $conflictMessage = '';

    // Pesan sesudah "Batalkan Trip Ini" berhasil (trip kosong diarsipkan).
    public string $cancelMessage = '';

    /**
     * 🔴 mount() SENGAJA TIDAK REDIRECT LAGI (fix 29 Juli 2026).
     *
     * Dulu: ada trip aktif → `$this->redirect(..., navigate: true)`. Dua masalah nyata:
     *
     * 1. REDIRECT DIAM-DIAM. Operator memilih "Kota 1" + 75 mika, tekan Mulai, lalu
     *    mendarat di trip LAIN (Trip Bebas "Semua Kios") tanpa satu kata penjelasan.
     *    Karena kartu kedai tak berlabel area, ia mengira "kebuka Pancing".
     * 2. TAK BISA DIANDALKAN SAAT BACK. `wire:navigate` menyimpan HTML halaman di
     *    `snapshotCache` JavaScript (vendor/livewire/livewire/dist/livewire.js:8127)
     *    dan memulihkannya pada `popstate` TANPA request ke server. Jadi mount() ini
     *    tak pernah jalan saat tombol BACK ditekan — dan header `no-store` dari
     *    middleware `no-store` pun tak berpengaruh, karena tak ada HTTP sama sekali.
     *
     * Sekarang: state dievaluasi ulang tiap RENDER (lihat activeTrip computed), jadi
     * roundtrip Livewire apa pun — termasuk $refresh yang dipicu guard back/forward di
     * partials/stale-page-guard.blade.php — mengembalikan halaman ke kebenaran.
     */
    public function mount(): void
    {
        // sengaja kosong — guard dipindah ke render (getActiveTripProperty).
    }

    // Memo per-REQUEST (properti private → tidak ikut snapshot Livewire). Sengaja
    // BUKAN computed property Livewire: cache-nya tak bisa kita invalidasi sendiri
    // setelah cancelActiveTrip() menghapus tripnya, dan kartu akan tetap tampil.
    private ?Trip $resolvedActiveTrip = null;
    private bool $activeTripResolved = false;

    /**
     * Trip yang MASIH BERJALAN hari ini milik operator ini, atau null.
     *
     * `latest('id')` (bukan `first()` polos = id TERKECIL) supaya kartu di layar
     * menyebutkan trip yang SAMA dengan yang akan dibuka ActiveTrip::mount() —
     * kalau tidak, kartunya sendiri jadi berbohong.
     */
    public function activeTrip(): ?Trip
    {
        if (! $this->activeTripResolved) {
            $this->resolvedActiveTrip = Trip::with('startingCluster')
                ->where('operator_id', auth()->id())
                ->whereDate('trip_date', today())
                ->whereNotNull('started_at')
                ->whereNull('ended_at')
                ->latest('id')
                ->first();
            $this->activeTripResolved = true;
        }

        return $this->resolvedActiveTrip;
    }

    private function forgetActiveTrip(): void
    {
        $this->resolvedActiveTrip = null;
        $this->activeTripResolved = false;
    }

    /**
     * Trip boleh DIBATALKAN hanya kalau benar-benar kosong: 0 kunjungan, 0 delivery,
     * 0 komisi. Sekali ada aktivitas, satu-satunya jalan keluar adalah "Akhiri Trip"
     * dari dalam trip (supaya stok & komisi ikut dibukukan).
     */
    private function tripHasActivity(Trip $trip): bool
    {
        return $trip->visits()->exists()
            || $trip->deliveries()->exists()
            || $trip->commissions()->exists();
    }

    public function canCancelActiveTrip(): bool
    {
        $trip = $this->activeTrip();

        return $trip !== null && ! $this->tripHasActivity($trip);
    }

    /**
     * Batalkan trip yang belum ada aktivitasnya = ARSIPKAN (soft delete, Ronde 1),
     * BUKAN hapus permanen. Bisa dipulihkan lewat `php artisan trip:restore {id}`.
     *
     * 🔒 Guard server-side lengkap — TIDAK bersandar pada tombol yang disembunyikan UI:
     *    (a) trip harus milik operator ini (query di-scope operator_id),
     *    (b) trip harus masih berjalan hari ini,
     *    (c) trip harus benar-benar kosong.
     */
    public function cancelActiveTrip(): void
    {
        $this->conflictMessage = '';
        $this->cancelMessage = '';

        $trip = $this->activeTrip();

        if (! $trip) {
            return; // sudah tidak ada trip berjalan — render berikutnya tampilkan form.
        }

        if ($this->tripHasActivity($trip)) {
            $this->conflictMessage = 'Trip #'.$trip->trip_number_of_day.' sudah ada aktivitas '
                .'(kunjungan/titipan tercatat), jadi tidak bisa dibatalkan. '
                .'Buka tripnya lalu pakai "Akhiri Trip" supaya stok & komisi ikut dibukukan.';

            return;
        }

        $nomor = $trip->trip_number_of_day;
        $trip->delete(); // SOFT DELETE = arsip; data anak (tak ada) tetap utuh.
        $this->forgetActiveTrip(); // memo harus basi sekarang, kalau tidak kartunya tetap tampil.

        $this->cancelMessage = 'Trip #'.$nomor.' dibatalkan dan diarsipkan. '
            .'Silakan mulai trip baru dengan area yang benar.';
    }

    public function getClustersProperty()
    {
        $ownerId = auth()->user()->owner_id;

        $clusters = Cluster::query()
            ->where('is_active', true)
            // Cluster sentinel walk-in (__walkin_owner_N) BUKAN area yang bisa dikunjungi;
            // is_active-nya default true jadi tanpa ini ia nongol sebagai "area" berisi
            // 1 kios "Penjualan Walk-in".
            ->excludeWalkInSentinel()
            ->when($ownerId !== null, fn($q) => $q->where('owner_id', $ownerId))
            ->withCount(['kiosks' => fn($q) => $q->where('is_active', true)->excludeWalkInSentinel()])
            ->orderBy('name')
            ->get();

        // Hindari N+1: semua kios aktif + kunjungan terakhir per kios diambil
        // dalam 2 query, urgency dihitung di PHP per cluster.
        $allKiosks = Kiosk::query()
            ->whereIn('cluster_id', $clusters->pluck('id'))
            ->where('is_active', true)
            ->get(['id', 'cluster_id', 'target_visit_interval_days', 'warning_visit_interval_days']);

        $lastVisits = KioskVisit::active()
            ->whereIn('kiosk_id', $allKiosks->pluck('id'))
            ->groupBy('kiosk_id')
            ->selectRaw('kiosk_id, MAX(visited_at) as last_visit')
            ->pluck('last_visit', 'kiosk_id');

        $kiosksPerCluster = $allKiosks->groupBy('cluster_id');

        return $clusters->map(function ($cluster) use ($kiosksPerCluster, $lastVisits) {
            $cluster->urgency_data = $this->calculateUrgency(
                $kiosksPerCluster->get($cluster->id, collect()),
                $lastVisits
            );
            return $cluster;
        });
    }

    protected function calculateUrgency($kiosks, $lastVisits): array
    {
        if ($kiosks->isEmpty()) {
            return [
                'level' => 'empty',
                'message' => 'Belum ada kios',
                'overdue_count' => 0,
                'warning_count' => 0,
                'never_count' => 0,
            ];
        }

        $overdue = 0;
        $warning = 0;
        $fresh = 0;
        $never = 0;

        foreach ($kiosks as $kiosk) {
            $lastVisit = $lastVisits[$kiosk->id] ?? null;

            if (! $lastVisit) {
                $never++;
                continue;
            }

            // abs(): Carbon 3 diffInDays bertanda (tanggal lampau = negatif),
            // tanpa abs() kios overdue tidak pernah terdeteksi.
            $days = (int) abs(now()->diffInDays(\Illuminate\Support\Carbon::parse($lastVisit)));
            $target = $kiosk->target_visit_interval_days ?? 14;
            $warningThreshold = $kiosk->warning_visit_interval_days ?? 10;

            if ($days > $target) {
                $overdue++;
            } elseif ($days > $warningThreshold) {
                $warning++;
            } else {
                $fresh++;
            }
        }

        $level = match (true) {
            $overdue > 0 => 'high',
            $warning > 0 => 'medium',
            $never > 0 && $fresh === 0 => 'unknown',
            default => 'low',
        };

        $messageParts = [];
        if ($overdue > 0) {
            $messageParts[] = "{$overdue} telat dikunjungi";
        }
        if ($warning > 0) {
            $messageParts[] = "{$warning} hampir telat";
        }
        if ($never > 0) {
            $messageParts[] = "{$never} belum pernah dikunjungi";
        }
        if (empty($messageParts) && $fresh > 0) {
            $messageParts[] = 'Semua kios aman';
        }

        return [
            'level' => $level,
            'message' => implode(', ', $messageParts),
            'overdue_count' => $overdue,
            'warning_count' => $warning,
            'never_count' => $never,
        ];
    }

    public function startTrip()
    {
        $this->conflictMessage = '';
        $this->cancelMessage = '';

        // Trip Bebas: cluster tidak wajib. Trip biasa: cluster wajib dipilih.
        // 🔒 exists di-scope owner operator (samakan pola CreateKiosk) → operator tak
        // bisa mulai trip pakai cluster owner lain walau ID dipaksa dari klien.
        $ownerId = auth()->user()->owner_id;
        $existsRule = Rule::exists('clusters', 'id');
        if ($ownerId !== null) {
            $existsRule->where('owner_id', $ownerId);
        }
        $clusterRule = $this->tripBebas ? ['nullable', $existsRule] : ['required', $existsRule];

        $this->validate([
            'selectedClusterId' => $clusterRule,
            'qtyCarried' => 'required|integer|min:1',
        ], [
            'selectedClusterId.required' => 'Pilih area dulu',
            'selectedClusterId.exists' => 'Area tidak valid',
            'qtyCarried.required' => 'Isi jumlah mika dulu',
            'qtyCarried.min' => 'Isi jumlah mika dulu',
        ]);

        // Proteksi 1: Intersepsi PHP (anti double-submit / double-tap tombol) +
        // jaring untuk halaman BASI yang dipulihkan tombol BACK.
        //
        // 🔴 WAJIB per-HARI INI — sengaja sama persis dengan activeTrip() di atas.
        // BUG 28 Juli 2026: dulu tanpa filter tanggal, jadi satu trip lama yang lupa
        // di-"Akhiri Trip" (ended_at masih null) MEMBAJAK setiap trip berikutnya.
        //
        // 🔴 TIDAK LAGI REDIRECT DIAM-DIAM (fix 29 Juli 2026). Inilah titik yang
        // membuat owner mengira "pilih Kota 1 → kebuka Pancing": operator menekan
        // Mulai dari halaman basi hasil BACK, intersepsi ini menemukan Trip Bebas
        // yang masih berjalan, lalu MELEMPARNYA ke trip itu tanpa sepatah kata.
        // Sekarang: tidak pindah halaman, tampilkan kartu trip berjalan + kalimat
        // tegas bahwa trip baru TIDAK dibuat. Operator yang memutuskan.
        $activeTrip = $this->activeTrip();

        if ($activeTrip) {
            $area = $activeTrip->starting_cluster_id
                ? ($activeTrip->startingCluster?->name ?? 'area awal')
                : 'Semua Kios';

            $this->conflictMessage = 'Kamu sudah punya trip berjalan ('.$area.'). '
                .'Trip baru TIDAK dibuat. Lanjutkan trip itu, atau batalkan dulu kalau salah pilih.';

            return null;
        }

        // Nomor trip harian sekarang unik PER OWNER (lihat idx_trip_owner_date_number).
        // Numbering ikut owner: trip ke-N bisnis hari ini lintas semua operator owner tsb.
        // Fallback ke operator_id kalau owner_id null (data lama / operator tanpa owner).
        $ownerId = auth()->user()->owner_id;

        $numberQuery = Trip::whereDate('trip_date', today());
        if ($ownerId !== null) {
            $numberQuery->where('owner_id', $ownerId);
        } else {
            $numberQuery->where('operator_id', auth()->id());
        }

        $nextNumber = ($numberQuery->max('trip_number_of_day') ?? 0) + 1;

        // Trip Bebas → tanpa cluster awal (semua kios aktif owner).
        $startingClusterId = $this->tripBebas ? null : $this->selectedClusterId;
        $notes = $this->tripBebas
            ? 'Trip Bebas (lintas cluster, semua kios)'
            : "Cluster awal: cluster_id={$startingClusterId}";

        // Proteksi 2: Jaring Throwable Total (Menangkap segala jenis error database)
        try {
            $trip = Trip::create([
                'owner_id' => auth()->user()->owner_id,
                'operator_id' => auth()->id(),
                'trip_date' => today(),
                'trip_number_of_day' => $nextNumber,
                'starting_cluster_id' => $startingClusterId,
                'started_at' => now(),
                'qty_carried_total' => $this->qtyCarried,
                'notes' => $notes,
            ]);
        } catch (\Throwable $e) {
            // Segala macam ledakan duplikasi data diserap di sini.
            // Ambil trip sah yang berhasil dibuat oleh request milidetik pertama.
            // Sama seperti Proteksi 1: di-scope HARI INI supaya trip basi hari lalu
            // tidak ikut terambil sebagai "trip yang barusan dibuat".
            $trip = Trip::where('operator_id', auth()->id())
                ->whereDate('trip_date', today())
                ->whereNull('ended_at')
                ->latest('id')
                ->first();
        }

        // Pengaman jika trip gagal dibuat dan gagal diambil (fallback mutlak)
        if (!$trip) {
            return redirect()->route('operator.dashboard');
        }

        return $this->redirect(route('operator.trip.active', $trip->id), navigate: true);
    }

    public function render()
    {
        // Trip berjalan dievaluasi tiap RENDER, bukan sekali di mount(). Itulah yang
        // membuat halaman basi hasil BACK bisa sembuh sendiri pada roundtrip Livewire
        // pertama (lihat catatan di mount()).
        $activeTrip = $this->activeTrip();

        return view('livewire.operator.start-trip', [
            'activeTrip' => $activeTrip,
            'canCancelActiveTrip' => $activeTrip !== null && $this->canCancelActiveTrip(),
            // Daftar area cuma dibutuhkan kalau formnya memang tampil — jangan
            // bayar query urgensi per-cluster saat layarnya kartu "trip berjalan".
            'clusters' => $activeTrip ? collect() : $this->clusters,
        ]);
    }
}
