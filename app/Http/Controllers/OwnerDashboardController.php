<?php

namespace App\Http\Controllers;

use App\Models\Cluster;
use App\Models\Kiosk;
use App\Models\KioskVisit;
use App\Models\ProcurementBatch;
use App\Models\Settlement;
use App\Models\Supplier;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
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
            'total_kios' => Kiosk::excludeWalkInSentinel()->whereHas('cluster', fn ($q) => $q->where('owner_id', $ownerId))->count(),
            'total_cluster' => Cluster::excludeWalkInSentinel()->where('owner_id', $ownerId)->count(),
            'total_supplier' => Supplier::where('owner_id', $ownerId)->count(),
            'total_operator' => User::where('role', 'operator')->where('owner_id', $ownerId)->count(),
        ];

        // Widget 1 — Omset hari ini: total uang masuk dari settlement hari ini
        $omsetHariIni = Settlement::whereDate('visit_date', today())
            ->where($settlementOwnerScope)
            ->sum('amount_paid');

        // Widget 2 — Kios overdue: kios aktif yang lewat target interval kunjungan.
        // Kunjungan terakhir di-batch 1 query (hindari N+1 per kios).
        $ownerKiosks = Kiosk::where('is_active', true)
            ->whereHas('cluster', fn ($q) => $q->where('owner_id', $ownerId))
            ->get();

        $lastVisitPerKiosk = KioskVisit::active()
            ->whereIn('kiosk_id', $ownerKiosks->pluck('id'))
            ->groupBy('kiosk_id')
            ->selectRaw('kiosk_id, MAX(visited_at) as last_visit')
            ->pluck('last_visit', 'kiosk_id');

        $overdueCount = $ownerKiosks
            ->filter(function ($kiosk) use ($lastVisitPerKiosk) {
                $lastVisit = $lastVisitPerKiosk[$kiosk->id] ?? null;

                if (! $lastVisit) {
                    return true; // belum pernah dikunjungi = overdue
                }

                $threshold = $kiosk->target_visit_interval_days ?: 10;

                // abs(): Carbon 3 diffInDays bertanda (tanggal lampau = negatif),
                // tanpa abs() kios yang sudah pernah dikunjungi tak pernah overdue.
                return abs(now()->diffInDays(\Illuminate\Support\Carbon::parse($lastVisit))) > $threshold;
            })
            ->count();

        // Widget 3 — Total outstanding: sisa tagihan dari settlement pending
        $totalOutstanding = Settlement::where('status', 'pending')
            ->where($settlementOwnerScope)
            ->selectRaw('SUM(amount_due - amount_paid) as total')
            ->value('total') ?? 0;

        // Widget — Kerugian titipan bulan ini: dari "Stop Tanpa Tagih" (write-off),
        // di mana seluruh sisa titipan dianggap basi/hilang (uang Rp 0). Nilai modal
        // = mika hilang x HPP per mika owner. Dipakai supaya kerugian tidak siluman.
        $kerugianBiji = (int) Settlement::where('is_writeoff', true)
            ->whereBetween('visit_date', [today()->startOfMonth(), today()->endOfMonth()])
            ->where($settlementOwnerScope)
            ->sum('qty_returned_expired');
        $kerugianMika = $kerugianBiji / 15;
        $hppPerMika = (float) ($user->hpp_per_mika ?? 9500);
        $kerugianTitipan = [
            'biji' => $kerugianBiji,
            'mika' => $kerugianMika,
            'nilai' => (int) round($kerugianMika * $hppPerMika),
        ];

        // Widget — Untung bersih hari ini: jumlah untung_bersih_owner dari trip
        // milik owner yang sudah selesai hari ini.
        $untungBersihHariIni = Trip::where('owner_id', $ownerId)
            ->whereDate('trip_date', today())
            ->whereNotNull('ended_at')
            ->get()
            ->sum('untung_bersih_owner');

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

        // Widget stok batch — sisa mika per batch (scoped owner).
        $batchStok = ProcurementBatch::where('owner_id', $ownerId)
            ->with('deliveries')
            ->orderBy('created_at')
            ->get()
            ->map(fn ($batch) => [
                'id' => $batch->id,
                'purchase_date' => $batch->purchase_date,
                'qty_packs' => $batch->qty_packs,
                'stok_tersisa' => $batch->stok_tersisa,
                'is_habis' => $batch->is_habis,
                'is_hampis_habis' => $batch->is_hampis_habis,
                'cost_per_pack' => $batch->cost_per_pack,
            ]);

        $totalStokTersisa = $batchStok->sum('stok_tersisa');

        // Widget prediksi dodol habis — kios yang dicek sisa bijinya oleh
        // operator (skenario check + sisa biji), max 5 terbaru agar query
        // accessor prediksi tetap terbatas.
        $prediksiKios = $ownerKiosks
            ->load('latestCheckVisit')
            ->filter(fn ($k) => $k->latestCheckVisit && $k->latestCheckVisit->sisa_biji)
            ->sortByDesc(fn ($k) => $k->latestCheckVisit->visited_at)
            ->take(5)
            ->map(fn ($k) => [
                'name' => $k->name,
                'sisa_biji' => (int) $k->latestCheckVisit->sisa_biji,
                'dicek_pada' => $k->latestCheckVisit->visited_at,
                'prediksi' => $k->prediksi_habis,
            ])
            ->values();

        // Widget — Kios berhenti (cut off): kios non-aktif milik owner, max 20
        // terbaru. Kosong → section tidak tampil (sama seperti prediksi habis).
        $stoppedKiosks = Kiosk::excludeWalkInSentinel()
            ->whereHas('cluster', fn ($q) => $q->where('owner_id', $ownerId))
            ->where('is_active', false)
            ->orderByDesc('stopped_at')
            ->take(20)
            ->get()
            ->map(fn ($k) => [
                'id' => $k->id,
                'name' => $k->name,
                'reason' => $k->stop_reason_label ?? '—',
                'stopped_at' => $k->stopped_at,
                'stopped_by' => $k->stopped_by,
            ]);

        return view('owner.dashboard', compact(
            'user',
            'stats',
            'omsetHariIni',
            'overdueCount',
            'totalOutstanding',
            'untungBersihHariIni',
            'chartLabels',
            'chartData',
            'activeTrips',
            'completedTrips',
            'batchStok',
            'totalStokTersisa',
            'prediksiKios',
            'stoppedKiosks',
            'kerugianTitipan'
        ));
    }

    /**
     * Aktifkan kembali kios yang di-stop (dari section "Kios Berhenti").
     * Reset jejak stop. Otorisasi: kios harus milik owner yang login.
     */
    public function reactivateKiosk(Kiosk $kiosk): RedirectResponse
    {
        abort_unless($kiosk->owner_id === auth()->id(), 403);

        $kiosk->update([
            'is_active' => true,
            'stopped_at' => null,
            'stop_reason' => null,
            'stopped_by' => null,
        ]);

        return back()->with('kiosk_reactivated', "Kios {$kiosk->name} diaktifkan kembali.");
    }
}
