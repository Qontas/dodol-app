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

        $stats = [
            'total_kios' => Kiosk::count(),
            'total_cluster' => Cluster::count(),
            'total_supplier' => Supplier::count(),
            'total_operator' => User::where('role', 'operator')->count(),
        ];

        // Widget 1 — Omset hari ini: total uang masuk dari settlement hari ini
        $omsetHariIni = Settlement::whereDate('visit_date', today())
            ->sum('amount_paid');

        // Widget 2 — Kios overdue: kios aktif yang lewat target interval kunjungan
        $overdueKiosks = Kiosk::where('is_active', true)
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
            ->selectRaw('SUM(amount_due - amount_paid) as total')
            ->value('total') ?? 0;

        // Data: sum(amount_paid) per hari 30 hari terakhir
        $startDate = today()->subDays(29);
        $endDate = today();

        $dailyOmsetRaw = Settlement::whereBetween('visit_date', [$startDate, $endDate])
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
            ->with(['operator', 'startingCluster', 'visits.kiosk', 'deliveries'])
            ->get();

        // Completed trips for ended trip reports
        $completedTrips = Trip::whereNotNull('ended_at')
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
