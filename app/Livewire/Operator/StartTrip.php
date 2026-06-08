<?php

namespace App\Livewire\Operator;

use App\Models\Cluster;
use App\Models\Kiosk;
use App\Models\KioskVisit;
use App\Models\Trip;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.operator')]
class StartTrip extends Component
{
    public ?int $selectedClusterId = null;
    public int $qtyCarried = 0;

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

        return Cluster::query()
            ->where('is_active', true)
            ->when($ownerId !== null, fn($q) => $q->where('owner_id', $ownerId))
            ->withCount(['kiosks' => fn($q) => $q->where('is_active', true)])
            ->orderBy('name')
            ->get()
            ->map(function ($cluster) {
                $cluster->urgency_data = $this->calculateUrgency($cluster->id);
                return $cluster;
            });
    }

    protected function calculateUrgency(int $clusterId): array
    {
        $kiosks = Kiosk::query()
            ->where('cluster_id', $clusterId)
            ->where('is_active', true)
            ->get();

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
            $lastVisit = KioskVisit::where('kiosk_id', $kiosk->id)
                ->latest('visited_at')
                ->value('visited_at');

            if (! $lastVisit) {
                $never++;
                continue;
            }

            $days = now()->diffInDays($lastVisit);
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
            $messageParts[] = "{$overdue} overdue";
        }
        if ($warning > 0) {
            $messageParts[] = "{$warning} warning";
        }
        if ($never > 0) {
            $messageParts[] = "{$never} belum visit";
        }
        if (empty($messageParts) && $fresh > 0) {
            $messageParts[] = 'Semua dalam interval normal';
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
        $this->validate([
            'selectedClusterId' => 'required|exists:clusters,id',
            'qtyCarried' => 'required|integer|min:1',
        ], [
            'selectedClusterId.required' => 'Pilih cluster dulu',
            'selectedClusterId.exists' => 'Cluster tidak valid',
            'qtyCarried.required' => 'Isi jumlah mika dulu',
            'qtyCarried.min' => 'Isi jumlah mika dulu',
        ]);

        // Proteksi 1: Intersepsi PHP
        $activeTrip = Trip::where('operator_id', auth()->id())
            ->whereNull('ended_at')
            ->first();

        if ($activeTrip) {
            return $this->redirect(route('operator.trip.active', $activeTrip->id), navigate: true);
        }

        $maxNumber = Trip::where('operator_id', auth()->id())
            ->whereDate('trip_date', today())
            ->max('trip_number_of_day');

        $nextNumber = ($maxNumber ?? 0) + 1;

        // Proteksi 2: Jaring Throwable Total (Menangkap segala jenis error database)
        try {
            $trip = Trip::create([
                'operator_id' => auth()->id(),
                'trip_date' => today(),
                'trip_number_of_day' => $nextNumber,
                'starting_cluster_id' => $this->selectedClusterId,
                'started_at' => now(),
                'qty_carried_total' => $this->qtyCarried,
                'notes' => "Cluster awal: cluster_id={$this->selectedClusterId}",
            ]);
        } catch (\Throwable $e) {
            // Segala macam ledakan duplikasi data diserap di sini.
            // Ambil trip sah yang berhasil dibuat oleh request milidetik pertama.
            $trip = Trip::where('operator_id', auth()->id())
                ->whereNull('ended_at')
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
