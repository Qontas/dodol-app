<?php

namespace App\Exports;

use App\Http\Controllers\Owner\MonthlyReportController;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

class MonthlyReportExport implements WithMultipleSheets
{
    public function __construct(private string $month, private int $ownerId) {}

    public function sheets(): array
    {
        $data = MonthlyReportController::buildMonthlyData($this->month, $this->ownerId);

        return [
            new MonthlyRekapSheet($data),
            new MonthlyKiosSheet($data),
        ];
    }
}

/**
 * Sheet 1 — rekap per trip + baris total.
 */
class MonthlyRekapSheet implements FromArray, WithHeadings, WithTitle
{
    public function __construct(private array $data) {}

    public function title(): string
    {
        return 'Rekap Trip';
    }

    public function headings(): array
    {
        return ['Tanggal', 'Operator', 'Kios Dikunjungi', 'Mika Drop', 'Omset', 'Komisi', 'Untung Bersih'];
    }

    public function array(): array
    {
        $rows = array_map(fn ($r) => [
            $r['trip_date'],
            $r['operator'],
            $r['kios_dikunjungi'],
            $r['mika_drop'],
            $r['omset'],
            $r['komisi'],
            $r['untung_bersih'],
        ], $this->data['rows']->all());

        $t = $this->data['totals'];
        $rows[] = ['TOTAL', '', $t['kios_dikunjungi'], $t['mika_drop'], $t['omset'], $t['komisi'], $t['untung_bersih']];

        return $rows;
    }
}

/**
 * Sheet 2 — analisis frekuensi kunjungan per kios.
 */
class MonthlyKiosSheet implements FromArray, WithHeadings, WithTitle
{
    public function __construct(private array $data) {}

    public function title(): string
    {
        return 'Analisis Kios';
    }

    public function headings(): array
    {
        return ['Nama Kios', 'Cluster', 'Jumlah Kunjungan', 'Jumlah Settle'];
    }

    public function array(): array
    {
        return array_map(fn ($r) => [
            $r['kiosk'],
            $r['cluster'],
            $r['kunjungan'],
            $r['settle'],
        ], $this->data['analisisKios']['frekuensi']->all());
    }
}
