<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * BRIEF 26 Okt 2026 item 3 — Excel Laporan Periode, PERSIS 3 sheet: Ringkasan, Per Trip,
 * Data Kunjungan (raw, 1 baris per kunjungan, kolom numerik sebagai angka asli — bukan
 * string ter-format — supaya siap difilter/pivot di Excel). Tidak ada cell merge, 1 baris
 * header per sheet. Data dari PeriodReportController::buildPeriodData() — tidak ada rumus
 * uang baru di file ini.
 */
class PeriodReportExport implements WithMultipleSheets
{
    public function __construct(private array $data) {}

    public function sheets(): array
    {
        return [
            new PeriodeRingkasanSheet($this->data),
            new PeriodePerTripSheet($this->data),
            new PeriodeDataKunjunganSheet($this->data),
        ];
    }
}

class PeriodeRingkasanSheet implements FromArray, WithHeadings, WithStrictNullComparison, WithTitle
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
        $t = $this->data['totals'];

        return [
            ['Periode', $this->data['label'] ?? ''],
            ['Usaha', $this->data['businessName']],
            ['Omset', $t['omset']],
            ['HPP', $t['hpp']],
            ['Untung Kotor', $t['untung_kotor']],
            ['Komisi', $t['komisi']],
            ['Untung Bersih', $t['untung_bersih']],
            ['Mika Diantar', $t['mika_diantar']],
            ['Kios Dikunjungi', $t['kios_dikunjungi']],
            ['Piutang Baru', $t['piutang_baru']],
        ];
    }
}

class PeriodePerTripSheet implements FromArray, WithHeadings, WithStrictNullComparison, WithTitle
{
    public function __construct(private array $data) {}

    public function title(): string
    {
        return 'Per Trip';
    }

    public function headings(): array
    {
        return ['Tanggal', 'Operator', 'Kios Dikunjungi', 'Mika Diantar', 'Omset', 'HPP', 'Untung Kotor', 'Komisi', 'Untung Bersih'];
    }

    public function array(): array
    {
        $trips = $this->data['trips'];

        $rows = $trips->map(fn ($r) => [
            $r['trip_date'],
            $r['operator'],
            $r['kios_dikunjungi'],
            $r['mika_diantar'],
            $r['omset'],
            $r['hpp'],
            $r['untung_kotor'],
            $r['komisi'],
            $r['untung_bersih'],
        ])->all();

        $rows[] = [
            'TOTAL', '',
            $trips->sum('kios_dikunjungi'),
            $trips->sum('mika_diantar'),
            $trips->sum('omset'),
            $trips->sum('hpp'),
            $trips->sum('untung_kotor'),
            $trips->sum('komisi'),
            $trips->sum('untung_bersih'),
        ];

        return $rows;
    }
}

/**
 * Sheet 3 — RAW, 1 baris per kunjungan, kolom PERSIS sesuai brief: Tanggal, Jam,
 * Operator, Area, Kios, Aksi, Mika Titip, Biji Terjual, BS, Tagihan, Uang Diterima,
 * Piutang, Janji Bayar, Catatan, Trip#. Dari VisitRowBuilder (sama dipakai detail
 * trip & export trip) — tanpa baris TOTAL (biar murni data mentah siap pivot).
 *
 * WithStrictNullComparison: tanpa ini, Maatwebsite\Excel pakai perbandingan
 * `==null` (loose) buat deteksi sel kosong, jadi literal 0 (Mika Titip visit
 * "Tagih Saja", BS 0 biji) & string "" ikut dianggap null → jadi sel KOSONG
 * bukan angka 0 asli. Melanggar syarat brief "kolom numerik angka asli".
 */
class PeriodeDataKunjunganSheet implements FromArray, WithHeadings, WithStrictNullComparison, WithTitle
{
    public function __construct(private array $data) {}

    public function title(): string
    {
        return 'Data Kunjungan';
    }

    public function headings(): array
    {
        return ['Tanggal', 'Jam', 'Operator', 'Area', 'Kios', 'Aksi', 'Mika Titip', 'Biji Terjual', 'BS', 'Tagihan', 'Uang Diterima', 'Piutang', 'Janji Bayar', 'Catatan', 'Trip#'];
    }

    public function array(): array
    {
        return $this->data['visitRows']->map(fn ($v) => [
            $v['visited_at']?->format('d-m-Y') ?? '',
            $v['visited_at']?->format('H:i') ?? '',
            $v['operator_name'],
            $v['area'],
            $v['kiosk'],
            $v['action'],
            $v['mika_titip'],
            $v['biji_terjual'],
            $v['bs_biji'],
            $v['tagihan'],
            $v['uang_diterima'],
            $v['piutang'],
            $v['janji_bayar'],
            $v['catatan'],
            $v['trip_number'],
        ])->all();
    }
}
