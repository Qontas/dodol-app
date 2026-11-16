<?php

namespace App\Exports;

use App\Support\KioskInsightsSummary;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithDrawings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

class KioskInsightsExport implements WithMultipleSheets
{
    public function __construct(private array $data, private bool $withPhotos = false) {}

    public static function columns(): array
    {
        return KioskInsightsSheet::columns();
    }

    public function sheets(): array
    {
        return array_map(fn (string $title) => new KioskInsightsSheet($this->data, $title, $this->withPhotos),
            ['Ringkasan', 'Perlu Tindakan', 'Daftar Kedai', 'Penjelasan']);
    }
}

/** One binder for every sheet: all strings, including metadata and URLs, are literal text. */
class KioskInsightsSheet extends DefaultValueBinder implements FromArray, WithCustomValueBinder, WithDrawings, WithEvents, WithStrictNullComparison, WithTitle
{
    private array $rows = [];

    private array $sectionRows = [];

    private array $recordRows = [];

    private array $formats = [];

    private array $links = [];

    private array $photos = [];

    public function __construct(private array $data, private string $sheetTitle, private bool $withPhotos) {}

    public function title(): string
    {
        return $this->sheetTitle;
    }

    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    /** [heading, type, plain-language definition] in stable export order. */
    public static function columns(): array
    {
        return [
            'id' => ['ID Kedai', 'int', 'Identitas unik kedai.'],
            'owner_id' => ['ID Owner', 'int', 'Identitas pemilik usaha.'],
            'business_name' => ['Usaha', 'text', 'Usaha pemilik kedai; bukan pemilik warung.'],
            'area' => ['Area', 'text', 'Kelompok area kedai.'],
            'name' => ['Nama Kedai', 'text', 'Nama kedai saat laporan dibuat.'],
            'owner_name' => ['Pemilik Kedai', 'text', 'Nama kontak pemilik kedai.'],
            'phone' => ['Telepon', 'text', 'Teks agar nol di depan dan tanda + tetap utuh.'],
            'address' => ['Alamat', 'text', 'Alamat kedai.'],
            'maps_url' => ['Google Maps', 'text', 'Hanya tautan HTTPS Google Maps yang dapat diklik.'],
            'type' => ['Tipe', 'text', 'Konsinyasi, Cash, atau Booking.'],
            'price_label' => ['Daftar Harga', 'text', 'Nama daftar harga saat ini.'],
            'price_per_pack' => ['Harga / Mika', 'rp', 'Harga per mika saat ini; bukan harga historis transaksi.'],
            'units_per_pack' => ['Biji / Mika', 'int', 'Isi per mika saat ini.'],
            'default_qty_mika' => ['Jatah Mika', 'int', 'Jatah titip saat ini.'],
            'is_active' => ['Aktif', 'bool', 'Ya atau Tidak pada saat laporan dibuat.'],
            'first_titip_date' => ['Titip Pertama', 'date', 'Tanggal titipan pertama.'],
            'notes' => ['Catatan', 'text', 'Catatan kedai.'],
            'frequent_operator' => ['Operator Terbanyak', 'text', 'Operator yang paling sering berkunjung.'],
            'visit_count' => ['Kunjungan', 'int', 'Kunjungan nyata dalam periode; trip sentinel bukan kunjungan.'],
            'visits_30' => ['Kunjungan 30 Hari', 'int', 'Kunjungan dalam 30 hari terakhir sampai tanggal acuan.'],
            'last_visit_at' => ['Kunjungan Terakhir', 'date', 'Tanggal kunjungan terakhir sampai tanggal acuan.'],
            'last_operator' => ['Operator Terakhir', 'text', 'Operator kunjungan terakhir.'],
            'days_since_visit' => ['Hari Sejak Kunjungan', 'decimal', 'Jarak hari dari tanggal acuan; kosong jika belum pernah dikunjungi.'],
            'avg_visit_gap_days' => ['Rerata Jarak (Hari)', 'decimal', 'Rata-rata jarak hari antar kunjungan.'],
            'delivered_packs' => ['Mika Diantar', 'decimal', 'Jumlah mika diantar dalam periode.'],
            'sold_units' => ['Biji Terjual', 'decimal', 'Jumlah biji terjual dalam periode.'],
            'sell_through_pct' => ['Sell-through', 'pct', 'Persentase biji terjual dari titipan yang sudah diselesaikan; hanya konsinyasi.'],
            'weekly_sales' => ['Laju Biji / Minggu', 'decimal', 'Laju tertimbang durasi siklus titip yang selesai, termasuk siklus terjual nol; bukan rerata persentase.'],
            'return_bs_pct' => ['Retur / BS', 'pct', 'Persentase seluruh retur (segar + kedaluwarsa) dari biji titipan siklus konsinyasi selesai.'],
            'revenue_total' => ['Omset Periode', 'rp', 'Uang diterima dalam periode, bukan nilai stok titipan.'],
            'revenue_30' => ['Omset 30 Hari', 'rp', 'Uang diterima 30 hari terakhir sampai tanggal acuan.'],
            'avg_revenue_per_visit' => ['Omset / Kunjungan', 'rp', 'Rata-rata uang diterima per kunjungan.'],
            'sold_out_count' => ['Siklus Habis', 'int', 'Jumlah siklus konsinyasi yang habis terjual.'],
            'settled_count' => ['Siklus Selesai', 'int', 'Jumlah settlement; termasuk settlement dengan pembayaran masih tertunda.'],
            'consignment_sold_units' => ['Biji Laku Konsinyasi', 'decimal', 'Biji terjual dari siklus konsinyasi selesai.'],
            'settled_units' => ['Biji Titipan Selesai', 'decimal', 'Biji titipan pada siklus konsinyasi selesai.'],
            'pending_packs' => ['Stok Titipan (Mika)', 'decimal', 'Stok titipan belum diselesaikan saat ini; termasuk titipan awal/sentinel. Bukan piutang.'],
            'pending_value' => ['Nilai Titipan', 'rp', 'Nilai stok titipan belum diselesaikan saat ini. Bukan uang yang sudah ditagih.'],
            'pending_age_days' => ['Umur Titipan (Hari)', 'decimal', 'Umur titipan tertua yang belum diselesaikan saat ini.'],
            'receivable' => ['Piutang', 'rp', 'Tagihan yang sudah timbul tetapi belum dibayar saat ini; terpisah dari stok titipan.'],
            'receivable_age_days' => ['Umur Piutang (Hari)', 'decimal', 'Umur piutang tertua yang masih terbuka saat ini.'],
            'label' => ['Label', 'text', 'Label performa sesuai aturan di sheet Penjelasan.'],
            'label_key' => ['Kode Label', 'text', 'Kode stabil untuk filter/pivot.'],
            'suggestions' => ['Saran Tindakan', 'list', 'Saran operasional, bukan perubahan jatah otomatis.'],
            'priority' => ['Prioritas', 'int', 'Angka lebih kecil didahulukan.'],
            'photo_status' => ['Status Foto', 'text', 'Tidak ada foto / Foto gagal dimuat / ok.'],
        ];
    }

    public function array(): array
    {
        $this->sectionRows = $this->recordRows = $this->formats = $this->links = $this->photos = [];
        $period = ($this->data['period_start'] ?? null) ? $this->data['period_start'].' s.d. '.$this->data['as_of'] : 'Seluruh riwayat s.d. '.$this->data['as_of'];
        $this->rows = [
            ['LAPORAN KEDAI — '.$this->sheetTitle],
            ['Usaha', ($this->data['all_owners'] ?? false) ? 'Semua owner' : ($this->data['business_name'] ?? '')],
            ['Tanggal acuan', $this->data['as_of'] ?? '', 'Dibuat', $this->data['generated_at'] ?? ''],
            ['Periode', $period],
            [null],
        ];
        if ($this->sheetTitle === 'Ringkasan') {
            $this->summary();
        } elseif ($this->sheetTitle === 'Penjelasan') {
            $this->explanation();
        } else {
            $this->records();
        }

        return $this->rows;
    }

    private function section(array $values): void
    {
        $this->rows[] = $values;
        $this->sectionRows[] = count($this->rows);
    }

    private function summary(): void
    {
        $s = KioskInsightsSummary::summarize($this->data);
        $this->section(['Indikator', 'Nilai', 'Keterangan']);
        foreach (['kiosks' => 'Jumlah kedai', 'active' => 'Kedai aktif', 'revenue_30' => 'Omset 30 hari',
            'pending_packs' => 'Stok titipan (mika)', 'pending_value' => 'Nilai stok titipan', 'receivable' => 'Piutang'] as $key => $label) {
            $this->rows[] = [$label, $s['totals'][$key], in_array($key, ['pending_packs', 'pending_value', 'receivable']) ? 'Posisi saat laporan dibuat; bukan periode historis' : 'Sampai tanggal acuan'];
            if (in_array($key, ['revenue_30', 'pending_value', 'receivable'])) {
                $this->formats['B'.count($this->rows)] = '"Rp" #,##0';
            }
        }
        $this->rows[] = ['Rerata sell-through', $s['avg_sell_through_pct'] === null ? null : $s['avg_sell_through_pct'] / 100, 'Rerata persentase kedai konsinyasi yang memiliki data; bukan laju penjualan tertimbang.'];
        $this->formats['B'.count($this->rows)] = '0.0%';
        $this->rows[] = [null];
        $this->section(['Usaha', 'Area', 'Kedai', 'Aktif', 'Omset 30 Hari', 'Nilai Titipan', 'Piutang']);
        foreach ($s['areas'] as $a) {
            $this->rows[] = array_values(array_intersect_key($a, array_flip(['business_name', 'area', 'kiosks', 'active', 'revenue_30', 'pending_value', 'receivable'])));
            foreach (['E', 'F', 'G'] as $col) {
                $this->formats[$col.count($this->rows)] = '"Rp" #,##0';
            }
        }
        $this->rows[] = [null];
        $this->section(['Distribusi Label', 'Jumlah Kedai']);
        $labels = $this->data['rules']['labels'] ?? [];
        foreach ($s['labels'] as $key => $count) {
            $this->rows[] = [$labels[$key] ?? $key, $count];
        }
        foreach (['top10' => '10 Kedai Tercepat', 'slow10' => '10 Kedai Terlambat'] as $key => $title) {
            $this->rows[] = [null];
            $this->section([$title, 'Hanya konsinyasi dengan sedikitnya '.($this->data['rules']['min_settlements'] ?? 3).' siklus selesai; urut laju biji/minggu.']);
            $this->section(['Usaha', 'Area', 'Kedai', 'Biji / Minggu', 'Sell-through', 'Siklus Selesai', 'Label']);
            foreach ($s[$key] as $r) {
                $this->rows[] = [$r['business_name'], $r['area'], $r['name'], $r['weekly_sales'], isset($r['sell_through_pct']) ? $r['sell_through_pct'] / 100 : null, $r['settled_count'], $r['label']];
                $this->formats['D'.count($this->rows)] = '#,##0.0';
                $this->formats['E'.count($this->rows)] = '0.0%';
            }
            if (! $s[$key]) {
                $this->rows[] = ['Belum ada kedai yang memenuhi syarat.'];
            }
        }
    }

    private function records(): void
    {
        $catalog = $this->sheetTitle === 'Daftar Kedai';
        $columns = $catalog ? self::columns() : array_intersect_key(self::columns(), array_flip([
            'id', 'business_name', 'area', 'name', 'phone', 'type', 'last_visit_at', 'days_since_visit', 'sell_through_pct',
            'weekly_sales', 'return_bs_pct', 'settled_count', 'pending_packs', 'pending_value', 'pending_age_days',
            'receivable', 'receivable_age_days', 'label', 'suggestions', 'priority',
        ]));
        $heading = ['Jenis Baris'];
        if ($catalog && $this->withPhotos) {
            $heading[] = 'Foto';
        }
        $this->section(array_merge($heading, array_column($columns, 0)));
        $records = $this->data['rows'] ?? [];
        if (! $catalog) {
            $records = array_values(array_filter($records, fn (array $r): bool => ! empty($r['suggestions'])));
            usort($records, fn (array $a, array $b): int => (($a['priority'] ?? 999) <=> ($b['priority'] ?? 999))
                ?: strnatcasecmp(($a['business_name'] ?? '').($a['area'] ?? '').($a['name'] ?? ''), ($b['business_name'] ?? '').($b['area'] ?? '').($b['name'] ?? '')));
        }
        $previous = null;
        foreach ($records as $r) {
            $group = json_encode([$r['owner_id'] ?? null, $r['area'] ?? '']);
            if ($catalog && $group !== $previous) {
                // Explicit row type and NO merged cells in the filter range: filter DATA to obtain pure records.
                $this->section(['AREA', ($r['business_name'] ?? '').' / '.(($r['area'] ?? '') ?: 'Tanpa area')]);
                $previous = $group;
            }
            $row = ['DATA'];
            $rowNumber = count($this->rows) + 1;
            if ($catalog && $this->withPhotos) {
                $row[] = '';
                if ($path = KioskInsightsSummary::thumbnail($r)) {
                    $this->photos[$rowNumber] = $path;
                } else {
                    $row[1] = KioskInsightsSummary::photoStatus($r);
                }
            }
            foreach ($columns as $key => [, $type]) {
                $value = $r[$key] ?? null;
                if ($key === 'photo_status') {
                    $value = KioskInsightsSummary::photoStatus($r);
                }
                $column = Coordinate::stringFromColumnIndex(count($row) + 1);
                $cell = $column.$rowNumber;
                if ($value !== null) {
                    $value = match ($type) {
                        'int' => (int) $value,
                        'rp', 'decimal' => (float) $value,
                        'pct' => (float) $value / 100,
                        'bool' => $value ? 'Ya' : 'Tidak',
                        'date' => $value === '' ? null : Date::dateTimeToExcel(Carbon::parse($value)),
                        'list' => implode("\n", (array) $value),
                        default => (string) $value,
                    };
                }
                $format = match ($type) {
                    'rp' => '"Rp" #,##0', 'decimal' => '#,##0.0', 'pct' => '0.0%', 'date' => '[$-421]dd mmm yyyy', default => null,
                };
                if ($format) {
                    $this->formats[$cell] = $format;
                }
                if ($key === 'maps_url' && ($url = KioskInsightsSummary::mapsUrl($value))) {
                    $this->links[$cell] = $url;
                }
                $row[] = $value;
            }
            $this->rows[] = $row;
            $this->recordRows[] = $rowNumber;
        }
    }

    private function explanation(): void
    {
        $this->section(['Baca Dahulu', 'Penjelasan']);
        foreach (self::methodology($this->data) as $key => $value) {
            $this->rows[] = [$key, $value];
        }
        $this->rows[] = [null];
        $this->section(['Aturan Aktif', 'Nilai']);
        foreach ($this->data['rules'] ?? [] as $key => $value) {
            $this->rows[] = [(string) $key, is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (is_bool($value) ? ($value ? 'Ya' : 'Tidak') : $value)];
        }
        $this->rows[] = [null];
        $this->section(['Kolom', 'Arti']);
        $this->rows[] = ['Jenis Baris', 'DATA = satu kedai; AREA = subjudul, bukan kedai. Filter DATA sebelum menghitung/pivot. Semua angka mentah tetap numerik, bukan teks Rupiah.'];
        $this->rows[] = ['Foto', 'Opsional, thumbnail lokal 60 piksel; foto rusak tidak menggagalkan laporan.'];
        foreach (self::columns() as [$heading, , $definition]) {
            $this->rows[] = [$heading, $definition];
        }
    }

    public static function methodology(array $data): array
    {
        $r = $data['rules'] ?? [];

        return [
            'Cakupan' => 'Identitas, harga, jatah, status aktif, stok titipan dan piutang adalah posisi saat laporan dibuat. Riwayat penjualan/kunjungan mengikuti periode laporan dan tanggal acuan.',
            'Performa' => 'Hanya kedai Konsinyasi. Cash dan Booking tidak dibandingkan memakai sell-through atau laju konsinyasi. Kolom kosong berarti tidak berlaku atau belum ada data; nol berarti nilai nol yang diketahui.',
            'Data minimum' => 'Sedikitnya '.($r['min_settlements'] ?? 3).' siklus selesai. Settlement dengan pembayaran tertunda tetap dihitung; titipan belum diselesaikan tidak masuk basis performa.',
            'Laju penjualan' => 'Biji per minggu dihitung dari siklus titipan selesai, ditimbang berdasarkan durasinya. Siklus terjual nol tetap dihitung. Top 10 / terlambat 10 diurutkan menurut laju ini, bukan omset.',
            'Label' => 'Lambat: sell-through di bawah '.($r['slow_sell_through_pct'] ?? 50).'%. Laris: sell-through sedikitnya '.($r['fast_sell_through_pct'] ?? 80).'% dan laju mencapai persentil '.($r['fast_percentile'] ?? 75).'. Retur BS sedikitnya '.($r['problem_bs_pct'] ?? 25).'% atau piutang berumur '.($r['debt_age_days'] ?? 30).' hari perlu perhatian. Label akhir mengikuti prioritas aturan; Tidak aktif (status nonaktif atau kunjungan lewat ambang '.($r['inactive_days'] ?? 30).' hari) dan Data belum cukup tidak menilai laju.',
            'Kunjungan' => 'Trip sentinel/titipan awal tetap masuk stok, tetapi bukan kunjungan nyata. Saran kunjungan memakai ambang '.($r['revisit_days'] ?? 14).' hari.',
            'Titipan vs piutang' => 'Titipan belum selesai adalah stok, bukan tagihan. Piutang adalah uang yang sudah ditagih tetapi belum dibayar. Jangan menjumlahkan keduanya sebagai omset.',
            'Saran' => 'Saran bukan keputusan otomatis. Target jatah dari laju biji per hari × rerata jarak kunjungan ÷ isi mika, dibulatkan; kenaikan dibatasi maksimum '.($r['max_quota_increase'] ?? 2).' mika dan tidak dipaksa naik. Kedai sehat tanpa saran tidak masuk Perlu Tindakan. Periksa keadaan kedai sebelum bertindak.',
            'Filter Excel' => 'Daftar Kedai berisi subjudul AREA. Untuk filter/pivot murni, pilih Jenis Baris = DATA. Kolom Usaha dan Area selalu diulang pada setiap kedai; subjudul tidak berisi angka.',
            'Keamanan' => 'Semua teks disimpan sebagai teks literal, bukan formula. Hanya tautan HTTPS Google Maps yang aktif; foto hanya thumbnail JPEG lokal, tanpa unduh jaringan saat render.',
        ];
    }

    public function drawings(): array
    {
        $drawings = [];
        foreach ($this->photos as $row => $path) {
            $drawing = new Drawing;
            $drawing->setName('Foto kedai');
            $drawing->setPath($path);
            $drawing->setHeight(56);
            $drawing->setWidth(56);
            $drawing->setCoordinates('B'.$row);
            $drawing->setOffsetX(2);
            $drawing->setOffsetY(2);
            $drawings[] = $drawing;
        }

        return $drawings;
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event): void {
            $sheet = $event->sheet->getDelegate();
            $endColumn = $sheet->getHighestColumn();
            $lastRow = $sheet->getHighestRow();
            $sheet->getSheetView()->setZoomScale(85);
            $sheet->getStyle('A1:'.$endColumn.$lastRow)->getFont()->setName('Calibri')->setSize(11)->getColor()->setRGB('3F2414');
            $sheet->getStyle('A1:'.$endColumn.$lastRow)->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
            $sheet->getStyle('A1')->getFont()->setSize(18)->setBold(true);
            $sheet->getRowDimension(1)->setRowHeight(30);
            $sheet->getDefaultColumnDimension()->setWidth(18);
            $sheet->getColumnDimension('A')->setWidth(19);
            $sheet->getColumnDimension('B')->setWidth($this->sheetTitle === 'Penjelasan' ? 105 : 30);
            foreach ($this->sectionRows as $row) {
                $range = 'A'.$row.':'.$endColumn.$row;
                $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($row === 6 ? '3F2414' : 'F5ECDD');
                $sheet->getStyle($range)->getFont()->setBold(true)->getColor()->setRGB($row === 6 ? 'FFFFFF' : '3F2414');
                $sheet->getRowDimension($row)->setRowHeight($row === 6 ? 34 : 28);
            }
            foreach ($this->formats as $cell => $format) {
                $sheet->getStyle($cell)->getNumberFormat()->setFormatCode($format);
            }
            foreach ($this->links as $cell => $url) {
                $sheet->getCell($cell)->getHyperlink()->setUrl($url);
                $sheet->getStyle($cell)->getFont()->setUnderline(true);
            }
            foreach ($this->recordRows as $i => $row) {
                $sheet->getRowDimension($row)->setRowHeight($this->sheetTitle === 'Daftar Kedai' && $this->withPhotos ? 45 : 42);
                if ($i % 2 === 1) {
                    $sheet->getStyle('A'.$row.':'.$endColumn.$row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FAF7F2');
                }
                $sheet->getStyle('A'.$row.':'.$endColumn.$row)->getBorders()->getBottom()->setBorderStyle(Border::BORDER_HAIR)->getColor()->setRGB('D9CFC2');
            }
            $records = in_array($this->sheetTitle, ['Daftar Kedai', 'Perlu Tindakan'], true);
            $sheet->freezePane($records ? 'D7' : 'B7');
            if ($records) {
                $sheet->setAutoFilter('A6:'.$endColumn.max(6, $lastRow));
                foreach ($this->rows[5] as $index => $heading) {
                    $col = Coordinate::stringFromColumnIndex($index + 1);
                    if (in_array($heading, ['Alamat', 'Catatan', 'Saran Tindakan', 'Google Maps'])) {
                        $sheet->getColumnDimension($col)->setWidth(42);
                    }
                }
            }
            if ($this->sheetTitle === 'Daftar Kedai' && $this->withPhotos) {
                $sheet->getColumnDimension('B')->setWidth(8.57); // approximately 60 px at default Calibri 11.
            }
            if ($this->sheetTitle === 'Penjelasan') {
                for ($row = 7; $row <= $lastRow; $row++) {
                    $sheet->getRowDimension($row)->setRowHeight(52);
                }
            }
            $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(6, 6)->setFitToWidth(1)->setFitToHeight(0);
        }];
    }
}
