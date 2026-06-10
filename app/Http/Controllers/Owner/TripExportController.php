<?php

namespace App\Http\Controllers\Owner;

use App\Exports\TripReportExport;
use App\Http\Controllers\Controller;
use App\Models\Trip;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class TripExportController extends Controller
{
    private function authorizeTrip(Trip $trip): void
    {
        abort_if(
            $trip->owner_id !== auth()->id() && ! auth()->user()->isSuperAdmin(),
            403
        );
    }

    /**
     * Bangun semua data laporan trip (dipakai PDF & sebagai referensi Excel).
     */
    public static function buildTripData(Trip $trip): array
    {
        $trip->loadMissing(['owner', 'operator', 'startingCluster', 'visits.kiosk']);

        $mikaDibawa = (int) ($trip->qty_carried_total ?? 0);
        $mikaDrop = (int) $trip->deliveries()->sum('qty_delivered');

        return [
            'trip' => $trip,
            'businessName' => $trip->owner?->name ?? 'Cemilan Qontas',
            'operatorName' => $trip->operator?->name ?? '—',
            'clusterName' => $trip->startingCluster?->name ?? 'Semua Kios',
            'summary' => [
                'mika_dibawa' => $mikaDibawa,
                'mika_drop' => $mikaDrop,
                'mika_sisa' => $mikaDibawa - $mikaDrop,
            ],
            'finansial' => [
                'omset' => (int) $trip->omset_val,
                'hpp' => (int) $trip->hpp_estimasi,
                'untung_kotor' => (int) $trip->untung_kotor,
                'komisi_reguler' => (int) $trip->komisi_reguler,
                'komisi_kios_baru' => (int) $trip->komisi_kios_baru,
                'total_komisi' => (int) $trip->komisi_rian,
                'untung_bersih' => (int) $trip->untung_bersih_owner,
            ],
            'visits' => $trip->visits->map(fn ($v) => [
                'kiosk' => $v->kiosk?->name ?? '—',
                'action' => self::visitActionLabel($v->visit_action),
                'time' => $v->visited_at?->format('d M Y H:i') ?? '—',
            ])->all(),
        ];
    }

    public static function visitActionLabel(?string $action): string
    {
        return match ($action) {
            'drop_and_settle' => 'Tagih + Titip Baru',
            'drop_only' => 'Titip Baru',
            'settle_only' => 'Tagih Saja',
            'check_only' => 'Cek Saja',
            'cash_sale' => 'Jual Cash',
            default => $action ?? '—',
        };
    }

    public function pdf(Trip $trip): Response
    {
        $this->authorizeTrip($trip);

        $pdf = Pdf::loadView('exports.trip-report-pdf', self::buildTripData($trip));

        return $pdf->download("trip-report-{$trip->trip_date->format('Y-m-d')}.pdf");
    }

    public function excel(Trip $trip): BinaryFileResponse
    {
        $this->authorizeTrip($trip);

        return Excel::download(
            new TripReportExport($trip),
            "trip-report-{$trip->trip_date->format('Y-m-d')}.xlsx"
        );
    }
}
