<?php

namespace App\Http\Controllers\Owner;

use App\Exports\MonthlyReportExport;
use App\Http\Controllers\Controller;
use App\Models\Kiosk;
use App\Models\KioskVisit;
use App\Models\Settlement;
use App\Models\Trip;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class MonthlyReportController extends Controller
{
    /**
     * Susun semua data laporan bulanan, di-scope ke owner yang login.
     * $month format 'Y-m'.
     */
    public static function buildMonthlyData(string $month, int $ownerId): array
    {
        $year = (int) substr($month, 0, 4);
        $mon = (int) substr($month, 5, 2);

        $trips = Trip::where('owner_id', $ownerId)
            ->whereNotNull('ended_at')
            ->whereYear('trip_date', $year)
            ->whereMonth('trip_date', $mon)
            // Trip sentinel migrasi (saldo awal kedai lama) dikecualikan — bisa jatuh
            // di bulan laporan NYATA kalau dibuat via kios:saldo-awal (trip_date =
            // tanggal spreadsheet, bukan 2000-01-01). Lihat Trip::scopeRealOperational().
            ->realOperational()
            ->with(['operator', 'startingCluster', 'visits', 'deliveries'])
            ->orderBy('trip_date')
            ->get();

        // Agregat finansial per trip DIBATCH (bukan accessor per-baris yang N+1 saat trip
        // banyak). Angka IDENTIK accessor Trip (omset_val/komisi_rian/untung_bersih_owner)
        // — lihat TripAggregator. kios_dikunjungi & mika_drop tetap dari koleksi ter-eager
        // (murah, sekaligus jaga semantik lama: visits->count() = SEMUA kunjungan).
        $owner = \App\Models\User::find($ownerId);
        $tripAgg = \App\Support\TripAggregator::for(
            $trips->pluck('id')->all(),
            $owner?->getKomisiPerMikaValue() ?? 1000.0,
        );

        $rows = $trips->map(fn (Trip $t) => [
            'trip_date' => $t->trip_date->format('d M Y'),
            'operator' => $t->operator?->name ?? '—',
            'kios_dikunjungi' => $t->visits->count(),
            'mika_drop' => (int) $t->deliveries->sum('qty_delivered'),
            // Tampilan tambahan "X mika (Y biji)" — dari koleksi `deliveries` yang SUDAH
            // eager-loaded di atas (accessor qty_biji_delivered murni komputasi, bukan
            // query baru), jadi tetap O(1) query berapa pun banyak trip di bulan ini.
            'biji_drop' => (int) $t->deliveries->sum(fn ($d) => $d->qty_biji_delivered),
            'omset' => (int) ($tripAgg[$t->id]['omset'] ?? 0),
            'komisi' => (int) ($tripAgg[$t->id]['komisi'] ?? 0),
            'untung_bersih' => (int) ($tripAgg[$t->id]['untung_bersih'] ?? 0),
        ]);

        $totals = [
            'kios_dikunjungi' => $rows->sum('kios_dikunjungi'),
            'mika_drop' => $rows->sum('mika_drop'),
            'biji_drop' => $rows->sum('biji_drop'),
            'omset' => $rows->sum('omset'),
            'komisi' => $rows->sum('komisi'),
            'untung_bersih' => $rows->sum('untung_bersih'),
        ];

        // Omset harian (untuk chart sederhana)
        $dailyOmset = Settlement::whereHas('delivery.kiosk.cluster', fn ($q) => $q->where('owner_id', $ownerId))
            ->whereYear('visit_date', $year)
            ->whereMonth('visit_date', $mon)
            ->selectRaw('visit_date, SUM(amount_paid) as total')
            ->groupBy('visit_date')
            ->orderBy('visit_date')
            ->get()
            ->map(fn ($r) => [
                'date' => \Illuminate\Support\Carbon::parse($r->visit_date)->format('d M'),
                'total' => (int) $r->total,
            ]);

        // Analisis kios
        $settledKioskIds = Settlement::whereHas('delivery.kiosk.cluster', fn ($q) => $q->where('owner_id', $ownerId))
            ->whereHas('delivery.kiosk', fn ($q) => $q->excludeWalkInSentinel())
            ->whereYear('visit_date', $year)
            ->whereMonth('visit_date', $mon)
            ->with('delivery:id,kiosk_id')
            ->get()
            ->pluck('delivery.kiosk_id')
            ->filter()
            ->unique();

        $monthVisits = KioskVisit::active()
            ->whereHas('trip', fn ($q) => $q->where('owner_id', $ownerId)
            ->whereYear('trip_date', $year)
            ->whereMonth('trip_date', $mon))
            ->whereHas('kiosk', fn ($q) => $q->excludeWalkInSentinel())
            ->with('kiosk.cluster')
            ->get();

        $kiosFrekuensi = $monthVisits->groupBy('kiosk_id')->map(fn ($g) => [
            'kiosk' => $g->first()->kiosk?->name ?? '—',
            'cluster' => $g->first()->kiosk?->cluster?->name ?? '—',
            'kunjungan' => $g->count(),
            'settle' => $g->whereNotNull('settled_delivery_id')->count(),
        ])->sortByDesc('kunjungan')->values();

        // Kios baru bulan ini = first_titip_date di bulan laporan DAN sudah punya
        // transaksi nyata (delivery yang ter-settle). Tanpa filter settlement,
        // kios hasil seeding saldo awal (delivery pending tanpa settlement,
        // first_titip_date = tanggal migrasi) ikut terhitung — itu bukan kios baru.
        $kiosBaruCount = Kiosk::whereYear('first_titip_date', $year)
            ->whereMonth('first_titip_date', $mon)
            ->whereHas('cluster', fn ($q) => $q->where('owner_id', $ownerId))
            ->whereHas('deliveries.settlement')
            ->count();

        // $owner sudah di-fetch di atas (dipakai juga untuk hpp/komisi TripAggregator).
        $hargaMika = (float) ($owner?->harga_mika ?? 200.00);

        // 1 query (bukan 2): mika & biji sekaligus — units_per_pack_snapshot sudah di
        // tabel deliveries sendiri, tak perlu join tambahan (lihat TripAggregator §1).
        $totalDiantarRow = \App\Models\Delivery::whereHas('trip', function ($q) use ($ownerId, $year, $mon) {
            $q->where('owner_id', $ownerId)
              ->whereYear('trip_date', $year)
              ->whereMonth('trip_date', $mon)
              ->realOperational();
        })->selectRaw('
                COALESCE(SUM(qty_delivered), 0) as mika,
                COALESCE(SUM(qty_delivered * COALESCE(units_per_pack_snapshot, 15)), 0) as biji
            ')->first();

        $totalMikaDiantar = (int) ($totalDiantarRow->mika ?? 0);
        $totalBijiDiantar = (int) ($totalDiantarRow->biji ?? 0);

        $modalMika = $totalMikaDiantar * $hargaMika;

        // 🔴 DIPERBAIKI 24 Okt 2026 (BRIEF "Satu Sumber Kebenaran Komisi", fix E) — dulu
        // SUM(commission_amount) dari tabel `commissions` (ditulis belakangan, hasil
        // reverse-engineer omset×margin×tarif%, lihat ActiveTrip::confirmEndTrip() —
        // beda rumus & bisa beda rounding tipis dari komisi_rian snapshot). Sekarang
        // pakai $totals['komisi'] — SUMBER YANG SAMA dengan tabel "Rekap Per Trip" di
        // halaman ini (TripAggregator, tarif beku per-trip), supaya dua angka komisi
        // di satu halaman tak pernah berbeda. Tabel `commissions` tetap ditulis seperti
        // semula di ActiveTrip, hanya TIDAK lagi dibaca untuk tampilan ini.
        $rekapKomisi = (float) $totals['komisi'];

        return [
            'month' => $month,
            'businessName' => $owner?->getBusinessNameValue() ?? 'Cemilan Qontas',
            'trips' => $trips,
            'rows' => $rows,
            'totals' => $totals,
            'dailyOmset' => $dailyOmset,
            'analisisKios' => [
                'total_kios_settled' => $settledKioskIds->count(),
                'kios_baru' => $kiosBaruCount,
                'frekuensi' => $kiosFrekuensi,
            ],
            'total_mika_diantar' => $totalMikaDiantar,
            'total_biji_diantar' => $totalBijiDiantar,
            'modal_mika' => $modalMika,
            'rekap_komisi' => $rekapKomisi,
        ];
    }

    public function index(Request $request): View
    {
        $month = $request->get('month', now()->format('Y-m'));
        $data = self::buildMonthlyData($month, (int) auth()->id());

        return view('owner.reports.monthly', $data);
    }

    public function pdf(Request $request): Response
    {
        $month = $request->get('month', now()->format('Y-m'));
        $data = self::buildMonthlyData($month, (int) auth()->id());

        $pdf = Pdf::loadView('exports.monthly-report-pdf', $data);

        return $pdf->download("laporan-bulanan-{$month}.pdf");
    }

    public function excel(Request $request): BinaryFileResponse
    {
        $month = $request->get('month', now()->format('Y-m'));

        return Excel::download(
            new MonthlyReportExport($month, (int) auth()->id()),
            "laporan-bulanan-{$month}.xlsx"
        );
    }
}
