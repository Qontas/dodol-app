<?php

namespace App\Http\Controllers;

use App\Models\Cluster;
use App\Models\Kiosk;
use App\Models\KioskVisit;
use App\Models\Settlement;
use App\Models\Supplier;
use App\Models\Trip;
use App\Models\User;
use Illuminate\View\View;

class OwnerDashboardController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();

        // Multi-tenant: owner hanya lihat data bisnisnya sendiri.
        $ownerId = $user->id;

        // Settlement Level 2 → owner lewat delivery.kiosk.cluster.owner_id.
        $settlementOwnerScope = fn ($q) => $q->whereHas(
            'delivery.kiosk.cluster',
            fn ($c) => $c->where('owner_id', $ownerId)
        );

        $stats = [
            'total_kios' => Kiosk::whereHas('cluster', fn ($q) => $q->where('owner_id', $ownerId))->count(),
            'total_cluster' => Cluster::where('owner_id', $ownerId)->count(),
            'total_supplier' => Supplier::where('owner_id', $ownerId)->count(),
            'total_operator' => User::where('role', 'operator')->where('owner_id', $ownerId)->count(),
        ];

        // Widget 1 — Omset hari ini: total uang masuk dari settlement hari ini
        $omsetHariIni = Settlement::whereDate('visit_date', today())
            ->where($settlementOwnerScope)
            ->sum('amount_paid');

        // Widget 2 — Kios overdue: kios aktif yang lewat target interval kunjungan
        $overdueKiosks = Kiosk::where('is_active', true)
            ->whereHas('cluster', fn ($q) => $q->where('owner_id', $ownerId))
            ->get()
            ->filter(function ($kiosk) {
                $lastVisit = KioskVisit::where('kiosk_id', $kiosk->id)
                    ->max('visited_at');

                if (! $lastVisit) {
                    return true; // belum pernah dikunjungi = overdue
                }

                $threshold = $kiosk->target_visit_interval_days ?: 10;

                return now()->diffInDays($lastVisit) > $threshold;
            });
        $overdueCount = $overdueKiosks->count();

        // Widget 3 — Total outstanding: sisa tagihan dari settlement pending
        $totalOutstanding = Settlement::where('status', 'pending')
            ->where($settlementOwnerScope)
            ->selectRaw('SUM(amount_due - amount_paid) as total')
            ->value('total') ?? 0;

        // Data: sum(amount_paid) per hari 30 hari terakhir
        $startDate = today()->subDays(29);
        $endDate = today();

        $dailyOmsetRaw = Settlement::whereBetween('visit_date', [$startDate, $endDate])
            ->where($settlementOwnerScope)
            ->groupBy('visit_date')
            ->selectRaw('visit_date, SUM(amount_paid) as total_omset')
            ->pluck('total_omset', 'visit_date')
            ->all();

        $chartLabels = [];
        $chartData = [];

        for ($date = clone $startDate; $date <= $endDate; $date->addDay()) {
            $formattedDate = $date->format('Y-m-d');
            $chartLabels[] = $date->format('d M');
            $chartData[] = (int) ($dailyOmsetRaw[$formattedDate] ?? 0);
        }

        // Active trips real-time progress
        $activeTrips = Trip::whereNull('ended_at')
            ->where('owner_id', $ownerId)
            ->with(['operator', 'startingCluster', 'visits.kiosk', 'deliveries'])
            ->get();

        // Completed trips for ended trip reports
        $completedTrips = Trip::whereNotNull('ended_at')
            ->where('owner_id', $ownerId)
            ->with(['operator', 'startingCluster', 'visits.kiosk', 'deliveries'])
            ->latest('ended_at')
            ->take(5)
            ->get();

        return view('owner.dashboard', compact(
            'user',
            'stats',
            'omsetHariIni',
            'overdueCount',
            'totalOutstanding',
            'chartLabels',
            'chartData',
            'activeTrips',
            'completedTrips'
        ));
    }
}
