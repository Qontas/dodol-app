<?php

namespace App\Exports;

use App\Http\Controllers\Owner\TripExportController;
use App\Models\Trip;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
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
class TripSummarySheet implements FromArray, WithHeadings, WithStrictNullComparison, WithTitle
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

        $rows = [
            ['Tanggal Trip', $this->data['trip']->trip_date->format('d M Y')],
            ['Operator', $this->data['operatorName']],
            ['Cluster', $this->data['clusterName']],
            ['Mika Dibawa', $s['mika_dibawa']],
            ['Mika Di-drop', $s['mika_drop']],
            ['Biji Di-drop', $s['biji_drop']],
            ['Mika Sisa', $s['mika_sisa']],
        ];

        // BRIEF 26 Okt 2026 item 4 — tampilan saja (bukan perbaikan rumus): tandai kalau
        // Mika Sisa negatif (lihat catatan investigasi di TripExportController::buildTripData()).
        if ($s['mika_sisa'] < 0) {
            $rows[] = ['⚠ Catatan Mika Sisa', 'Negatif — kemungkinan ada mika BS (Dodol Sisa) yang ikut terhitung keluar dari stok motor. Cek ulang stok fisik.'];
        }

        $rows = array_merge($rows, [
            ['Omset', $f['omset']],
            ['HPP', $f['hpp']],
            ['Untung Kotor', $f['untung_kotor']],
            ['Mika Komisi (Drop)', $f['mika_komisi']],
            ['Biji Komisi (Drop)', $f['biji_komisi']],
            ["Komisi {$this->data['operatorName']}", $f['total_komisi']],
            ['Untung Bersih Owner', $f['untung_bersih']],
        ]);

        return $rows;
    }
}

/**
 * Sheet 2 — detail kunjungan per kios. BRIEF 26 Okt 2026 item 2: dulu 3 kolom
 * (Kios/Aksi/Waktu) tanpa angka — sekarang 10 kolom lengkap + baris TOTAL, dari
 * VisitRowBuilder (sama yang dipakai PDF trip & halaman web detail trip).
 *
 * WithStrictNullComparison: tanpa ini, Maatwebsite\Excel membandingkan tiap nilai
 * array() dengan `==null` (loose) untuk tahu mana yang "kosong" — jadi literal 0
 * (mis. BS 0 biji, Mika Titip 0 di visit "Tagih Saja") & string "" ikut dianggap
 * null dan jadi SEL KOSONG, bukan angka 0 asli. Melanggar syarat "kolom numerik
 * angka asli" (item 3) & bikin filter/pivot Excel owner meleset.
 */
class TripVisitsSheet implements FromArray, WithHeadings, WithStrictNullComparison, WithTitle
{
    public function __construct(private array $data) {}

    public function title(): string
    {
        return 'Detail Kunjungan';
    }

    public function headings(): array
    {
        return ['Kios', 'Aksi', 'Waktu', 'Mika Titip', 'Biji Terjual', 'BS (biji)', 'Tagihan (Rp)', 'Uang Diterima (Rp)', 'Piutang (Rp)', 'Catatan/Janji Bayar'];
    }

    public function array(): array
    {
        $visits = $this->data['visits'];

        $rows = array_map(fn ($v) => [
            $v['kiosk'],
            $v['action'],
            $v['time'],
            $v['mika_titip'],
            $v['biji_terjual'],
            $v['bs_biji'],
            $v['tagihan'],
            $v['uang_diterima'],
            $v['piutang'],
            $v['catatan_gabungan'],
        ], $visits);

        $rows[] = [
            'TOTAL', '', '',
            array_sum(array_column($visits, 'mika_titip')),
            array_sum(array_column($visits, 'biji_terjual')),
            array_sum(array_column($visits, 'bs_biji')),
            array_sum(array_column($visits, 'tagihan')),
            array_sum(array_column($visits, 'uang_diterima')),
            array_sum(array_column($visits, 'piutang')),
            '',
        ];

        return $rows;
    }
}
