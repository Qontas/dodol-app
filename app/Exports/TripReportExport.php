<?php

namespace App\Exports;

use App\Http\Controllers\Owner\TripExportController;
use App\Models\Trip;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

class TripReportExport implements WithMultipleSheets
{
    public function __construct(private Trip $trip) {}

    public function sheets(): array
    {
        $data = TripExportController::buildTripData($this->trip);

        return [
            new TripSummarySheet($data),
            new TripVisitsSheet($data),
        ];
    }
}

/**
 * Sheet 1 — ringkasan finansial trip.
 */
class TripSummarySheet implements FromArray, WithHeadings, WithTitle
{
    public function __construct(private array $data) {}

    public function title(): string
    {
        return 'Ringkasan';
    }

    public function headings(): array
    {
        return ['Keterangan', 'Nilai'];
    }

    public function array(): array
    {
        $f = $this->data['finansial'];
        $s = $this->data['summary'];

        return [
            ['Tanggal Trip', $this->data['trip']->trip_date->format('d M Y')],
            ['Operator', $this->data['operatorName']],
            ['Cluster', $this->data['clusterName']],
            ['Mika Dibawa', $s['mika_dibawa']],
            ['Mika Di-drop', $s['mika_drop']],
            ['Mika Sisa', $s['mika_sisa']],
            ['Omset', $f['omset']],
            ['HPP', $f['hpp']],
            ['Untung Kotor', $f['untung_kotor']],
            ['Mika Komisi (Drop)', $f['mika_komisi']],
            ['Komisi Rian', $f['total_komisi']],
            ['Untung Bersih Owner', $f['untung_bersih']],
        ];
    }
}

/**
 * Sheet 2 — detail kunjungan per kios.
 */
class TripVisitsSheet implements FromArray, WithHeadings, WithTitle
{
    public function __construct(private array $data) {}

    public function title(): string
    {
        return 'Detail Kunjungan';
    }

    public function headings(): array
    {
        return ['Kios', 'Aksi', 'Waktu'];
    }

    public function array(): array
    {
        return array_map(
            fn ($v) => [$v['kiosk'], $v['action'], $v['time']],
            $this->data['visits']
        );
    }
}
