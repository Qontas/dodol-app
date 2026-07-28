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

    public function mount(): void
    {
        $existingTrip = Trip::where('operator_id', auth()->id())
            ->whereDate('trip_date', today())
            ->whereNotNull('started_at')
            ->whereNull('ended_at')
            ->first();

        if ($existingTrip) {
            $this->redirect(route('operator.trip.active', $existingTrip->id), navigate: true);
        }
    }

    public function getClustersProperty()
    {
        $ownerId = auth()->user()->owner_id;

        $clusters = Cluster::query()
            ->where('is_active', true)
            ->when($ownerId !== null, fn($q) => $q->where('owner_id', $ownerId))
            ->withCount(['kiosks' => fn($q) => $q->where('is_active', true)])
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

        // Proteksi 1: Intersepsi PHP (anti double-submit / double-tap tombol).
        //
        // 🔴 WAJIB per-HARI INI — sengaja sama persis dengan guard mount() di atas.
        // BUG 28 Juli 2026: dulu tanpa filter tanggal, jadi satu trip lama yang lupa
        // di-"Akhiri Trip" (ended_at masih null) MEMBAJAK setiap trip berikutnya:
        // operator pilih area "Kota 1", ditekan Mulai → intersepsi ini ketemu trip
        // "Pancing" kemarin → redirect ke sana, trip Kota 1 TAK PERNAH dibuat.
        // Diulang berapa kali pun hasilnya sama. Scope hari ini menutup itu tanpa
        // melemahkan proteksi double-submit (dua klik terjadi di hari yang sama).
        $activeTrip = Trip::where('operator_id', auth()->id())
            ->whereDate('trip_date', today())
            ->whereNotNull('started_at')
            ->whereNull('ended_at')
            ->first();

        if ($activeTrip) {
            return $this->redirect(route('operator.trip.active', $activeTrip->id), navigate: true);
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
        return view('livewire.operator.start-trip', [
            'clusters' => $this->clusters,
        ]);
    }
}
