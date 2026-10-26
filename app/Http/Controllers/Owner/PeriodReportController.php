<?php

namespace App\Http\Controllers\Owner;

use App\Exports\PeriodReportExport;
use App\Http\Controllers\Controller;
use App\Models\Trip;
use App\Models\User;
use App\Support\ExportFilename;
use App\Support\TripAggregator;
use App\Support\VisitRowBuilder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * BRIEF 26 Okt 2026 item 3 — "Laporan Periode": rentang tanggal bebas (hari ini/minggu
 * ini/bulan ini/custom) + filter operator, dibangun dari TripAggregator/VisitRowBuilder
 * yang SAMA dipakai dashboard/Riwayat Trip/Laporan Bulanan — TIDAK ada rumus uang baru,
 * hanya query trip dengan rentang tanggal berbeda. Laporan Bulanan LAMA tetap ada
 * terpisah (tidak disentuh) — lihat MonthlyReportController.
 */
class PeriodReportController extends Controller
{
    /**
     * Rentang tanggal dari quick-pick ('today'/'week'/'month'/'custom') + label manusia
     * (dipakai judul halaman & nama file export). Minggu = Senin–Minggu.
     *
     * @return array{0: string, 1: string, 2: string} [start Y-m-d, end Y-m-d, label]
     */
    public static function resolveRange(string $range, ?string $from, ?string $to): array
    {
        $today = Carbon::now()->startOfDay();

        return match ($range) {
            'today' => [$today->toDateString(), $today->toDateString(), 'Hari Ini '.$today->format('d M Y')],
            'week' => self::weekRange($today),
            'month' => [
                $today->copy()->startOfMonth()->toDateString(),
                $today->copy()->endOfMonth()->toDateString(),
                $today->translatedFormat('F Y'),
            ],
            default => self::customRange($from, $to, $today),
        };
    }

    private static function weekRange(Carbon $today): array
    {
        $start = $today->copy()->startOfWeek(Carbon::MONDAY);
        $end = $today->copy()->endOfWeek(Carbon::SUNDAY);

        return [$start->toDateString(), $end->toDateString(), 'Minggu Ini '.$start->format('d M').' - '.$end->format('d M Y')];
    }

    private static function customRange(?string $from, ?string $to, Carbon $today): array
    {
        $start = $from ? Carbon::parse($from)->startOfDay() : $today->copy()->startOfMonth();
        $end = $to ? Carbon::parse($to)->startOfDay() : $today->copy();
        if ($end->lt($start)) {
            [$start, $end] = [$end, $start];
        }

        return [$start->toDateString(), $end->toDateString(), $start->format('d M Y').' - '.$end->format('d M Y')];
    }

    /**
     * Susun semua data Laporan Periode, di-scope ke owner. Semua angka uang & komisi
     * DIBATCH via TripAggregator::for() (sumber sama dengan dashboard/Riwayat Trip/
     * Laporan Bulanan) — jangan tulis rumus baru di sini. Detail per kunjungan dari
     * VisitRowBuilder (sama dipakai detail trip & export trip).
     */
    public static function buildPeriodData(string $startDate, string $endDate, ?int $operatorId, int $ownerId): array
    {
        $owner = User::find($ownerId);
        $komisiPerMika = $owner?->getKomisiPerMikaValue() ?? 1000.0;

        $trips = Trip::where('owner_id', $ownerId)
            ->whereNotNull('ended_at')
            ->whereDate('trip_date', '>=', $startDate)
            ->whereDate('trip_date', '<=', $endDate)
            // Trip sentinel migrasi dikecualikan (Trip::scopeRealOperational()); trip
            // diarsip (SoftDeletes) sudah otomatis dibuang global scope — tanpa withTrashed().
            ->realOperational()
            ->when($operatorId, fn ($q) => $q->where('operator_id', $operatorId))
            ->with(array_merge(['operator', 'startingCluster'], VisitRowBuilder::eagerLoad()))
            ->orderBy('trip_date')
            ->get();

        $tripAgg = TripAggregator::for($trips->pluck('id')->all(), $komisiPerMika);

        $visitRows = collect();
        $perTrip = $trips->map(function (Trip $t) use ($tripAgg, $visitRows) {
            foreach (VisitRowBuilder::forTrip($t) as $row) {
                $visitRows->push(array_merge($row, [
                    'operator_name' => $t->operator?->name ?? '—',
                    'trip_number' => $t->trip_number_of_day,
                ]));
            }

            $a = $tripAgg[$t->id] ?? [];

            return [
                'trip_id' => $t->id,
                'trip_date' => $t->trip_date->format('d M Y'),
                'operator' => $t->operator?->name ?? '—',
                'kios_dikunjungi' => (int) ($a['active_visits'] ?? 0),
                'mika_diantar' => (int) ($a['mika_diantar'] ?? 0),
                'omset' => (int) ($a['omset'] ?? 0),
                'hpp' => (int) ($a['hpp_estimasi'] ?? 0),
                'untung_kotor' => (int) ($a['untung_kotor'] ?? 0),
                'komisi' => (int) ($a['komisi'] ?? 0),
                'untung_bersih' => (int) ($a['untung_bersih'] ?? 0),
            ];
        })->values();

        $totals = [
            'omset' => (int) $perTrip->sum('omset'),
            'hpp' => (int) $perTrip->sum('hpp'),
            'untung_kotor' => (int) $perTrip->sum('untung_kotor'),
            'komisi' => (int) $perTrip->sum('komisi'),
            'untung_bersih' => (int) $perTrip->sum('untung_bersih'),
            'mika_diantar' => (int) $perTrip->sum('mika_diantar'),
            'kios_dikunjungi' => (int) $perTrip->sum('kios_dikunjungi'),
            // Piutang BARU yang timbul di periode ini (bukan total piutang sepanjang
            // waktu) — sum kolom 'piutang' VisitRowBuilder lintas semua kunjungan
            // periode ini (formula sama dgn kartu "Total Outstanding" dashboard,
            // di-scope ke tanggal kunjungan dalam rentang periode).
            'piutang_baru' => (int) $visitRows->sum('piutang'),
        ];

        $perOperator = $perTrip->groupBy('operator')->map(fn ($rows, $operator) => [
            'operator' => $operator,
            'trip_count' => $rows->count(),
            'kios_dikunjungi' => (int) $rows->sum('kios_dikunjungi'),
            'mika_diantar' => (int) $rows->sum('mika_diantar'),
            'omset' => (int) $rows->sum('omset'),
            'komisi' => (int) $rows->sum('komisi'),
            'untung_bersih' => (int) $rows->sum('untung_bersih'),
        ])->sortByDesc('omset')->values();

        return [
            'trips' => $perTrip,
            'totals' => $totals,
            'perOperator' => $perOperator,
            'visitRows' => $visitRows->values(),
            'businessName' => $owner?->getBusinessNameValue() ?? 'Cemilan Qontas',
            'komisiPerMika' => $komisiPerMika,
        ];
    }

    private function paramsFromRequest(Request $request): array
    {
        $range = $request->get('range', 'month');
        $operatorId = $request->get('operator_id') ?: null;
        [$startDate, $endDate, $label] = self::resolveRange($range, $request->get('from'), $request->get('to'));

        return [$range, $operatorId ? (int) $operatorId : null, $startDate, $endDate, $label];
    }

    public function index(Request $request): View
    {
        [$range, $operatorId, $startDate, $endDate, $label] = $this->paramsFromRequest($request);

        $data = self::buildPeriodData($startDate, $endDate, $operatorId, (int) auth()->id());

        $operators = User::where('role', 'operator')
            ->where('owner_id', (int) auth()->id())
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('owner.reports.periode', array_merge($data, [
            'range' => $range,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'label' => $label,
            'operatorId' => $operatorId,
            'operators' => $operators,
        ]));
    }

    public function pdf(Request $request): Response
    {
        [, $operatorId, $startDate, $endDate, $label] = $this->paramsFromRequest($request);

        $data = self::buildPeriodData($startDate, $endDate, $operatorId, (int) auth()->id());
        $data['label'] = $label;
        $data['startDate'] = $startDate;
        $data['endDate'] = $endDate;

        $pdf = Pdf::loadView('exports.periode-report-pdf', $data);

        return $pdf->download(ExportFilename::periodePdf($label, $data['businessName']));
    }

    public function excel(Request $request): BinaryFileResponse
    {
        [, $operatorId, $startDate, $endDate, $label] = $this->paramsFromRequest($request);

        $data = self::buildPeriodData($startDate, $endDate, $operatorId, (int) auth()->id());
        $data['label'] = $label;

        return Excel::download(
            new PeriodReportExport($data),
            ExportFilename::periodeExcel($label, $data['businessName'])
        );
    }
}
