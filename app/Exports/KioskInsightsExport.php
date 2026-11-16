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

    private array $labelCells = [];

    /** Baris subjudul area (bukan data kedai): bergaya, tanpa merge sel, tanpa wrap. */
    private array $areaRows = [];

    /** Kunci kolom per posisi kolom (A=0) pada sheet daftar, untuk lebar & format. */
    private array $columnKeys = [];

    /** Baris SUBTOTAL area dan baris TOTAL (angka biasa, bukan formula). */
    private array $subtotalRows = [];

    private array $totalRows = [];

    /** Baris terakhir rentang autofilter; baris TOTAL sengaja di luar rentang ini. */
    private int $filterEndRow = 6;

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

    /**
     * [heading, type, plain-language definition, lebar kolom] — URUTAN = urutan kolom di sheet "Daftar Kedai",
     * disusun untuk pembaca: apa kedainya, bagaimana kondisinya, apa yang harus dilakukan, baru data pendukung.
     * "Perlu Tindakan" memakai urutan yang sama dengan subset kolom (lihat ACTION_COLUMNS).
     * Tipe 'mika' & 'days' = bilangan bulat. Kolom teknis (ID, kode label, prioritas, status foto) sengaja tidak ada.
     */
    public static function columns(): array
    {
        return [
            // 1. Apa & bagaimana kondisinya
            'name' => ['Nama Kedai', 'text', 'Nama kedai saat laporan dibuat.', 28],
            'business_name' => ['Usaha', 'text', 'Usaha pemilik kedai (hanya tampil pada laporan semua owner).', 22],
            'area' => ['Area', 'text', 'Kelompok area kedai.', 14],
            'maps_url' => ['Google Maps', 'text', 'Tautan "Buka Maps" yang dapat diklik; hanya tautan HTTPS Google Maps yang aktif.', 14],
            'address' => ['Alamat', 'text', 'Alamat kedai.', 40],
            'label' => ['Label', 'text', 'Label performa sesuai aturan di bagian "Aturan Aktif".', 16],
            'suggestions' => ['Saran Tindakan', 'list', 'Saran operasional, bukan perubahan jatah otomatis. Baris tanpa saran tidak masuk "Perlu Tindakan".', 46],
            'sell_through_pct' => ['% Laku', 'pct', 'Persentase biji terjual dari titipan yang sudah diselesaikan; hanya konsinyasi.', 10],
            'weekly_sales' => ['Laju Jual (biji/minggu)', 'decimal', 'Laju tertimbang durasi siklus titip yang selesai, termasuk siklus terjual nol; bukan rerata persentase.', 13],
            'pending_packs' => ['Mika di Kedai Sekarang', 'mika', 'Mika titipan yang masih berada di kedai (belum ditagih) saat laporan dibuat; termasuk titipan awal/sentinel. Bukan piutang.', 12],
            'pending_value' => ['Nilai Mika di Kedai', 'rp', 'Nilai mika yang masih berada di kedai saat laporan dibuat. Bukan uang yang sudah ditagih.', 15],
            'receivable' => ['Piutang', 'rp', 'Tagihan yang sudah timbul tetapi belum dibayar saat ini; terpisah dari mika di kedai.', 14],
            'receivable_age_days' => ['Umur Piutang (hari)', 'days', 'Umur piutang tertua yang masih terbuka saat ini.', 11],
            'last_visit_at' => ['Kunjungan Terakhir', 'date', 'Tanggal kunjungan terakhir sampai tanggal acuan.', 15],
            'last_operator' => ['Operator Terakhir', 'text', 'Operator kunjungan terakhir.', 16],
            'days_since_visit' => ['Hari Sejak Kunjungan', 'days', 'Jarak hari dari tanggal acuan; kosong jika belum pernah dikunjungi.', 11],
            // 2. Identitas
            'owner_name' => ['Pemilik Kedai', 'text', 'Nama kontak pemilik kedai.', 20],
            'phone' => ['Telepon', 'text', 'Teks agar nol di depan dan tanda + tetap utuh.', 16],
            'type' => ['Tipe', 'text', 'Konsinyasi, Cash, atau Booking.', 12],
            'price_label' => ['Daftar Harga', 'text', 'Nama daftar harga saat ini beserta harga per mika dan per biji.', 36],
            'price_per_pack' => ['Harga / Mika', 'rp', 'Harga per mika saat ini; bukan harga historis transaksi.', 13],
            'units_per_pack' => ['Biji / Mika', 'int', 'Isi per mika saat ini.', 9],
            'default_qty_mika' => ['Jatah Titipan (mika)', 'mika', 'Jatah titip saat ini.', 10],
            'is_active' => ['Aktif', 'bool', 'Ya atau Tidak pada saat laporan dibuat.', 8],
            'first_titip_date' => ['Titip Pertama', 'date', 'Tanggal titipan pertama.', 14],
            'notes' => ['Catatan', 'text', 'Catatan kedai.', 30],
            'frequent_operator' => ['Operator Terbanyak', 'text', 'Operator yang paling sering berkunjung.', 16],
            // 3. Metrik detail
            'visit_count' => ['Kunjungan', 'int', 'Kunjungan nyata dalam periode; trip sentinel bukan kunjungan.', 11],
            'visits_30' => ['Kunjungan 30 Hari', 'int', 'Kunjungan dalam 30 hari terakhir sampai tanggal acuan.', 11],
            'avg_visit_gap_days' => ['Rerata Jarak Kunjungan (hari)', 'decimal', 'Rata-rata jarak hari antar kunjungan. Dipakai untuk menilai kapan kedai perlu dikunjungi lagi.', 13],
            'delivered_packs' => ['Mika Diantar', 'mika', 'Jumlah mika diantar dalam periode.', 11],
            'sold_units' => ['Biji Terjual', 'decimal', 'Jumlah biji terjual dalam periode (termasuk cash).', 11],
            'bs_pct' => ['% BS', 'pct', 'Biji kedaluwarsa/rusak saja ÷ biji titipan konsinyasi yang sudah ditagih. Tidak termasuk retur segar.', 10],
            'fresh_return_pct' => ['Sisa Dibawa Pulang (%)', 'pct', 'Biji retur segar saja ÷ biji titipan konsinyasi yang sudah ditagih. Informasi sisa belum laku, bukan kerugian atau pemicu label.', 14],
            'revenue_total' => ['Omset Total', 'rp', 'amount_paid settlement pada trip penagih selesai sepanjang riwayat. Pembayaran berikutnya memperbarui nilai periode trip asal; bukan arus kas tanggal bayar.', 15],
            'revenue_30' => ['Omset 30 Hari', 'rp', 'amount_paid pada trip penagih bertanggal dalam 30 hari terakhir; bukan tanggal pembayaran cicilan.', 15],
            'avg_revenue_per_visit' => ['Omset / Kunjungan', 'rp', 'Rata-rata uang diterima per kunjungan.', 15],
            'sold_out_count' => ['Siklus Habis', 'int', 'Jumlah tagihan konsinyasi dengan laku ≥95% dari biji ditagih.', 10],
            'settled_count' => ['Siklus Selesai', 'int', 'Jumlah settlement; termasuk settlement dengan pembayaran masih tertunda.', 10],
            'pending_age_days' => ['Umur Titipan (hari)', 'days', 'Umur titipan tertua yang belum diselesaikan saat ini.', 11],
        ];
    }

    /** Subset kolom sheet "Perlu Tindakan" (urutan mengikuti columns()). */
    private const ACTION_COLUMNS = [
        'name', 'business_name', 'area', 'maps_url', 'address', 'label', 'suggestions', 'sell_through_pct', 'weekly_sales',
        'pending_packs', 'pending_value', 'receivable', 'receivable_age_days', 'last_visit_at', 'last_operator', 'days_since_visit',
        'owner_name', 'phone', 'type', 'bs_pct', 'fresh_return_pct', 'settled_count', 'pending_age_days',
    ];

    /** Kolom yang benar-benar tampil: kolom Usaha hanya pada laporan semua owner. */
    private function layout(bool $catalog): array
    {
        $columns = $catalog ? self::columns() : array_intersect_key(self::columns(), array_flip(self::ACTION_COLUMNS));
        if (! ($this->data['all_owners'] ?? false)) {
            unset($columns['business_name']);
        }

        return $columns;
    }

    public function array(): array
    {
        $this->sectionRows = $this->areaRows = $this->recordRows = $this->formats = $this->links = $this->photos = $this->labelCells = [];
        $this->columnKeys = $this->subtotalRows = $this->totalRows = [];
        $this->filterEndRow = 6;
        $meta = KioskInsightsSummary::meta($this->data);
        $this->rows = [
            ['LAPORAN KEDAI — '.$this->sheetTitle],
            ['Usaha', ($this->data['all_owners'] ?? false) ? 'Semua owner' : ($this->data['business_name'] ?? '')],
            ['Tanggal acuan', $meta['as_of'], 'Dibuat', $meta['generated']],
            ['Periode', $meta['period']],
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
            'pending_packs' => 'Mika di kedai sekarang', 'pending_value' => 'Nilai mika di kedai', 'receivable' => 'Piutang'] as $key => $label) {
            $this->rows[] = [$label, $s['totals'][$key], in_array($key, ['pending_packs', 'pending_value', 'receivable']) ? 'Posisi saat laporan dibuat; bukan periode historis' : 'Sampai tanggal acuan'];
            if (in_array($key, ['revenue_30', 'pending_value', 'receivable'])) {
                $this->formats['B'.count($this->rows)] = '"Rp" #,##0';
            } elseif (in_array($key, ['kiosks', 'active', 'pending_packs'])) {
                $this->formats['B'.count($this->rows)] = '#,##0'; // bilangan bulat, tanpa desimal
            }
        }
        $this->rows[] = ['Rerata % laku', $s['avg_sell_through_pct'] === null ? null : $s['avg_sell_through_pct'] / 100, 'Rerata persentase kedai konsinyasi yang memiliki data; bukan laju penjualan tertimbang.'];
        $this->formats['B'.count($this->rows)] = '0.0%';
        $this->rows[] = [null];
        $this->section(['Usaha', 'Area', 'Kedai', 'Aktif', 'Omset 30 Hari', 'Mika di Kedai', 'Nilai Mika di Kedai', 'Piutang']);
        foreach ($s['areas'] as $a) {
            $this->rows[] = [$a['business_name'], $a['area'], $a['kiosks'], $a['active'], $a['revenue_30'], $a['pending_packs'], $a['pending_value'], $a['receivable']];
            foreach (['E' => '"Rp" #,##0', 'F' => '#,##0', 'G' => '"Rp" #,##0', 'H' => '"Rp" #,##0'] as $col => $format) {
                $this->formats[$col.count($this->rows)] = $format;
            }
        }
        $this->rows[] = [null];
        $this->section(['Distribusi Label', 'Jumlah Kedai']);
        $labels = $this->data['rules']['labels'] ?? [];
        foreach ($s['labels'] as $key => $count) {
            $this->rows[] = [$labels[$key] ?? $key, $count];
        }
        foreach (['top10' => '10 Kedai Paling Laris', 'slow10' => '10 Kedai Paling Lambat Laku'] as $key => $title) {
            $this->rows[] = [null];
            $this->section([$title, 'Hanya konsinyasi dengan sedikitnya '.($this->data['rules']['min_settlements'] ?? 3).' siklus selesai, tidak termasuk label Tidak aktif dan Data belum cukup; urut laju biji/minggu.']);
            $this->section(['Usaha', 'Area', 'Kedai', 'Biji / Minggu', '% Laku', 'Siklus Selesai', 'Label']);
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
        $photos = $catalog && $this->withPhotos;
        $columns = $this->layout($catalog);
        // Foto selalu kolom pertama bila diminta; "Tidak ada foto" / "Foto gagal dimuat" ditulis di sel itu.
        $this->columnKeys = array_merge($photos ? ['__photo'] : [], array_keys($columns));
        $this->section(array_merge($photos ? ['Foto'] : [], array_column($columns, 0)));

        $records = $this->data['rows'] ?? [];
        if (! $catalog) {
            // Urutan baris = prioritas tindakan (piutang lama → lama tak dikunjungi → BS → jatah); angka
            // prioritasnya sendiri tidak ditampilkan. Hanya kedai yang punya saran.
            $records = array_values(array_filter($records, fn (array $r): bool => ! empty($r['suggestions'])));
            usort($records, fn (array $a, array $b): int => (($a['priority'] ?? 999) <=> ($b['priority'] ?? 999))
                ?: strnatcasecmp(($a['business_name'] ?? '').($a['area'] ?? '').($a['name'] ?? ''), ($b['business_name'] ?? '').($b['area'] ?? '').($b['name'] ?? '')));
        }
        $previous = null;
        $groups = [];
        $grand = ['kiosks' => 0, 'packs' => 0.0, 'value' => 0.0];
        foreach ($records as $r) {
            $group = json_encode([$r['owner_id'] ?? null, $r['area'] ?? '']);
            if ($catalog && $group !== $previous) {
                // Subtotal area sebelumnya ditutup tepat sebelum subjudul area berikutnya.
                if ($previous !== null) {
                    $this->subtotalRow($groups[$previous]);
                }
                // Subjudul area: satu baris bergaya, TANPA merge sel (aman untuk filter). Teks di kolom paling kiri
                // yang tidak ikut digeser; baris ini ditandai lewat gaya, bukan kolom teknis "Jenis Baris".
                $title = ($this->data['all_owners'] ?? false) ? ($r['business_name'] ?? '').' — ' : '';
                $label = $title.(($r['area'] ?? '') ?: 'Tanpa area');
                $this->rows[] = array_merge($photos ? [''] : [], [$label]);
                $this->areaRows[] = count($this->rows);
                $previous = $group;
            }
            // Jumlah angka (bukan formula) per area dan keseluruhan: aman terhadap filter/sort Excel.
            $groups[$group] ??= ['business' => $r['business_name'] ?? '', 'area' => ($r['area'] ?? '') ?: 'Tanpa area', 'kiosks' => 0, 'packs' => 0.0, 'value' => 0.0];
            $packs = (float) ($r['pending_packs'] ?? 0);
            $value = (float) ($r['pending_value'] ?? 0);
            $groups[$group]['kiosks']++;
            $groups[$group]['packs'] += $packs;
            $groups[$group]['value'] += $value;
            $grand['kiosks']++;
            $grand['packs'] += $packs;
            $grand['value'] += $value;
            $row = [];
            $rowNumber = count($this->rows) + 1;
            if ($photos) {
                if ($path = KioskInsightsSummary::thumbnail($r)) {
                    $this->photos[$rowNumber] = $path;
                    $row[] = '';
                } else {
                    $row[] = KioskInsightsSummary::photoStatus($r);
                }
            }
            foreach ($columns as $key => [, $type]) {
                $value = $r[$key] ?? null;
                $column = Coordinate::stringFromColumnIndex(count($row) + 1);
                $cell = $column.$rowNumber;
                if ($key === 'label') {
                    $this->labelCells[$cell] = $r['label_key'] ?? 'insufficient';
                }
                if ($value !== null) {
                    $value = match ($type) {
                        'int', 'mika', 'days' => (int) round((float) $value),
                        'rp', 'decimal' => (float) $value,
                        'pct' => (float) $value / 100,
                        'bool' => $value ? 'Ya' : 'Tidak',
                        'date' => $value === '' ? null : Date::dateTimeToExcel(Carbon::parse($value)),
                        'list' => implode("\n", (array) $value),
                        default => (string) $value,
                    };
                }
                $format = match ($type) {
                    'rp' => '"Rp" #,##0', 'decimal' => '#,##0.0', 'pct' => '0.0%', 'mika', 'days' => '#,##0',
                    'date' => '[$-421]dd mmm yyyy', default => null,
                };
                if ($format) {
                    $this->formats[$cell] = $format;
                }
                if ($key === 'maps_url' && ($url = KioskInsightsSummary::mapsUrl($value))) {
                    // Tautan HTTPS Google Maps yang valid tampil sebagai teks "Buka Maps"; URL tak valid tetap teks literal tanpa tautan.
                    $this->links[$cell] = $url;
                    $value = 'Buka Maps';
                }
                $row[] = $value;
            }
            $this->rows[] = $row;
            $this->recordRows[] = $rowNumber;
        }
        $this->filterEndRow = count($this->rows);
        if ($records === []) {
            return;
        }
        if ($catalog) {
            $this->subtotalRow($groups[$previous]);
            $this->filterEndRow = count($this->rows);
        } else {
            // "Perlu Tindakan" diurut menurut prioritas, jadi area tidak berurutan: subtotal per area ditaruh di bawah
            // daftar (di luar rentang filter), bukan di sela baris.
            $this->rows[] = [null];
            $this->section(['Subtotal per area — hanya kedai pada daftar ini']);
            uasort($groups, fn (array $a, array $b): int => strnatcasecmp($a['business'].'|'.$a['area'], $b['business'].'|'.$b['area']));
            foreach ($groups as $group) {
                $this->subtotalRow($group);
            }
        }
        // TOTAL di luar rentang autofilter (satu baris kosong sebagai pemisah) supaya tidak ikut tersaring/terurut sebagai kedai.
        $this->rows[] = [null];
        $this->rows[] = $this->summaryRow('TOTAL — '.$grand['kiosks'].' kedai', null, null, $grand['packs'], $grand['value']);
        $this->totalRows[] = count($this->rows);
    }

    /** @param array{business: string, area: string, kiosks: int, packs: float, value: float} $group */
    private function subtotalRow(array $group): void
    {
        $this->rows[] = $this->summaryRow('SUBTOTAL — '.$group['kiosks'].' kedai', $group['business'], $group['area'], $group['packs'], $group['value']);
        $this->subtotalRows[] = count($this->rows);
    }

    /** Baris jumlah: label di kolom Nama Kedai, jumlah mika & nilainya di kolomnya masing-masing, sisanya kosong. */
    private function summaryRow(string $label, ?string $business, ?string $area, float $packs, float $value): array
    {
        $rowNumber = count($this->rows) + 1;
        $row = [];
        foreach ($this->columnKeys as $index => $key) {
            $cell = Coordinate::stringFromColumnIndex($index + 1).$rowNumber;
            if ($key === 'pending_packs') {
                $this->formats[$cell] = '#,##0';
            } elseif ($key === 'pending_value') {
                $this->formats[$cell] = '"Rp" #,##0';
            }
            $row[] = match ($key) {
                'name' => $label,
                'business_name' => $business,
                'area' => $area,
                'pending_packs' => (int) round($packs),
                'pending_value' => $value,
                default => null,
            };
        }

        return $row;
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
        $this->rows[] = ['Foto', 'Opsional (Export Excel + Foto): thumbnail lokal 60 piksel. Kedai tanpa foto tertulis "Tidak ada foto"; foto yang gagal dimuat tertulis "Foto gagal dimuat" dan tidak menggagalkan laporan.'];
        foreach (self::columns() as [$heading, , $definition]) {
            $this->rows[] = [$heading, $definition];
        }
    }

    public static function methodology(array $data): array
    {
        $r = $data['rules'] ?? [];

        return [
            'Cakupan' => 'Identitas, harga, jatah, status aktif, mika di kedai dan piutang adalah posisi saat laporan dibuat. Riwayat penjualan/kunjungan mengikuti periode laporan dan tanggal acuan.',
            'Performa' => 'Hanya kedai Konsinyasi. Cash dan Booking tidak dibandingkan memakai % laku atau laju konsinyasi. Kolom kosong berarti tidak berlaku atau belum ada data; nol berarti nilai nol yang diketahui.',
            '% Laku' => 'Biji terjual ÷ biji yang dititipkan pada titipan yang sudah ditagih, dalam persen. Hanya kedai konsinyasi.',
            'Data minimum' => 'Sedikitnya '.($r['min_settlements'] ?? 3).' siklus selesai. Settlement dengan pembayaran tertunda tetap dihitung; titipan belum diselesaikan tidak masuk basis performa.',
            'Laju penjualan' => 'Biji per minggu dihitung dari siklus titipan selesai, ditimbang berdasarkan durasinya. Siklus terjual nol tetap dihitung. Daftar "10 kedai paling laris" dan "10 kedai paling lambat laku" diurutkan menurut laju ini, bukan omset, dan tidak menyertakan kedai berlabel Tidak aktif atau Data belum cukup.',
            '% BS / Sisa' => '% BS = biji kedaluwarsa/rusak saja ÷ biji titipan ditagih ×100%. Sisa Dibawa Pulang (%) = retur segar saja ÷ basis sama; hanya informasi belum laku, bukan kerugian dan tidak memicu Bermasalah.',
            'Label' => 'Prioritas: Tidak aktif > Data belum cukup > Bermasalah > Laris > Lambat > Stabil. Bermasalah HANYA bila % BS ≥'.($r['problem_bs_pct'] ?? 10).'% atau ada piutang berumur ≥'.($r['debt_age_days'] ?? 30).' hari. Lambat: % laku <'.($r['slow_sell_through_pct'] ?? 60).'%. Laris: % laku ≥'.($r['fast_sell_through_pct'] ?? 80).'% dan laju mencapai persentil '.($r['fast_percentile'] ?? 75).'. Ambang dari config/kiosk_insights.php: KIOSK_INSIGHTS_BS_PCT (default 10), KIOSK_INSIGHTS_SLOW_PCT (default 60), KIOSK_INSIGHTS_DEBT_DAYS (default 30). Tidak aktif: status nonaktif atau kunjungan lewat '.($r['inactive_days'] ?? 30).' hari.',
            'Kunjungan' => 'Trip sentinel/titipan awal tetap masuk stok, tetapi bukan kunjungan nyata.',
            'Saran "Kunjungi"' => 'Muncul bila hari sejak kunjungan terakhir sudah melewati maksimum dari '.($r['revisit_min_days'] ?? 10).' hari dan '.($r['revisit_gap_multiplier'] ?? 1.5).' × rata-rata jarak kunjungan kedai itu sendiri (kedai yang biasa dikunjungi tiap 7 hari diingatkan lebih cepat daripada kedai yang biasa tiap 21 hari). Kedai dengan riwayat kurang dari '.($r['revisit_min_gaps'] ?? 3).' jarak kunjungan memakai ambang tetap '.($r['revisit_days'] ?? 14).' hari.',
            'Subtotal dan total' => 'Di Daftar Kedai, tiap area ditutup baris SUBTOTAL (mika di kedai dan nilainya) dan baris TOTAL tebal ada di paling bawah, di luar rentang filter. Di Perlu Tindakan, subtotal per area ada di blok bawah daftar. Semuanya angka biasa (bukan rumus), hanya menjumlah kedai yang tampil di sheet itu, dan tidak berubah saat Anda memfilter. Baris SUBTOTAL/TOTAL bukan kedai: abaikan saat sort.',
            'Titipan vs piutang' => 'Mika di kedai (titipan belum ditagih) adalah stok, bukan tagihan. Piutang adalah uang yang sudah ditagih tetapi belum dibayar. Jangan menjumlahkan keduanya sebagai omset.',
            'Omset' => 'Jumlah amount_paid settlement mengikuti tanggal trip penagih nyata yang selesai, sama dengan laporan periode. Cicilan memperbarui settlement lama, sehingga bukan laporan arus kas berdasarkan tanggal bayar. Trip/kedai walk-in tidak masuk laporan per kedai ini.',
            'Saran' => 'Saran bukan keputusan otomatis. Data belum cukup: tanpa saran performa BS/jatah; piutang dan kunjungi tetap boleh. Habis (laku ≥95%) pada ≥2 dari 3 tagihan konsinyasi terakhir berarti permintaan tersensor: tawarkan jatah saat ini +1 meski estimasi sama. Estimasi laju biji/hari × rerata jarak ÷ isi mika boleh memberi lebih, dibatasi maksimum '.min(2, max(0, $r['max_quota_increase'] ?? 2)).' mika. Tidak menaikkan kedai bermasalah/nonaktif. Kedai sehat tanpa saran tidak masuk Perlu Tindakan. Periksa keadaan kedai sebelum bertindak.',
            'Filter Excel' => 'Daftar Kedai dikelompokkan per area dengan baris subjudul berwarna (bukan data kedai, tanpa angka). Kolom Area diulang pada setiap kedai, jadi filter/pivot cukup memakai kolom Area; abaikan baris subjudul. Urutan baris di "Perlu Tindakan" sudah mencerminkan prioritas: piutang lama, lama tidak dikunjungi, BS tinggi, lalu jatah.',
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
            $drawing->setCoordinates('A'.$row); // kolom Foto = kolom pertama
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
            // Judul tidak boleh terpotong oleh lebar kolom A: gabungkan hanya A1 sampai kolom terakhir yang terlihat,
            // tanpa wrap. Baris area/data TIDAK digabung (filter tetap aman).
            $sheet->mergeCells('A1:'.$endColumn.'1');
            $sheet->getStyle('A1')->getAlignment()->setWrapText(false)->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getRowDimension(1)->setRowHeight(30);
            $sheet->getDefaultColumnDimension()->setWidth(18);
            $sheet->getColumnDimension('A')->setWidth(19);
            $sheet->getColumnDimension('B')->setWidth($this->sheetTitle === 'Penjelasan' ? 105 : 30);
            $records = in_array($this->sheetTitle, ['Daftar Kedai', 'Perlu Tindakan'], true);
            if ($records) {
                // Lebar per kolom dari definisi kolom (bukan tebakan per judul), supaya teks panjang terbungkus rapi.
                $widths = collect(self::columns())->map(fn (array $c): int => $c[3])->all();
                foreach ($this->columnKeys as $index => $key) {
                    $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($key === '__photo' ? 11 : ($widths[$key] ?? 14));
                }
            }
            foreach ($this->sectionRows as $row) {
                $range = 'A'.$row.':'.$endColumn.$row;
                $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($row === 6 ? '3F2414' : 'F5ECDD');
                $sheet->getStyle($range)->getFont()->setBold(true)->getColor()->setRGB($row === 6 ? 'FFFFFF' : '3F2414');
                $sheet->getRowDimension($row)->setRowHeight($row === 6 ? 34 : 28);
            }
            if ($records) {
                // Apply one format per column, not ~15,000 style mutations for 450 kiosks.
                $columnFormats = [];
                foreach ($this->formats as $cell => $format) {
                    $columnFormats[preg_replace('/[0-9]/', '', $cell)] = $format;
                }
                foreach ($columnFormats as $column => $format) {
                    $sheet->getStyle($column.'7:'.$column.max(7, $lastRow))->getNumberFormat()->setFormatCode($format);
                }
            } else {
                foreach ($this->formats as $cell => $format) {
                    $sheet->getStyle($cell)->getNumberFormat()->setFormatCode($format);
                }
            }
            foreach ($this->links as $cell => $url) {
                $sheet->getCell($cell)->getHyperlink()->setUrl($url);
                $sheet->getStyle($cell)->getFont()->setUnderline(true);
            }
            foreach ($this->recordRows as $i => $row) {
                $sheet->getRowDimension($row)->setRowHeight($this->sheetTitle === 'Daftar Kedai' && $this->withPhotos ? 45 : 42);
            }
            // Subjudul area: baris bergaya (bukan data), tanpa wrap dan tanpa merge supaya filter tetap aman.
            foreach ($this->areaRows as $row) {
                $range = 'A'.$row.':'.$endColumn.$row;
                $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E9DCC6');
                $sheet->getStyle($range)->getFont()->setBold(true)->setSize(12)->getColor()->setRGB('3F2414');
                $sheet->getStyle($range)->getAlignment()->setWrapText(false)->setVertical(Alignment::VERTICAL_CENTER);
                $sheet->getRowDimension($row)->setRowHeight(24);
            }
            // Bekukan header + kolom identitas utama: [Foto +] Nama Kedai tetap terlihat saat menggulir ke kanan.
            $frozenColumns = count(array_intersect(array_slice($this->columnKeys, 0, 2), ['__photo', 'name']));
            $freezeCell = $records ? Coordinate::stringFromColumnIndex($frozenColumns + 1).'7' : 'B7';
            // topLeftCell eksplisit = sel freeze (pane bawah/kanan mulai di sana, bukan A1 sehingga baris 1–6 tidak
            // tampil dobel) dan sel aktif/seleksi awal juga di sel itu, bukan A1 yang berada di pane beku.
            $sheet->freezePane($freezeCell, $freezeCell);
            if ($records) {
                // Rentang filter berhenti sebelum baris TOTAL (dan, di "Perlu Tindakan", sebelum blok subtotal).
                $sheet->setAutoFilter('A6:'.$endColumn.max(6, $this->filterEndRow));
                // Subtotal area & TOTAL: tebal, berlatar jelas, tanpa wrap supaya label terbaca penuh.
                foreach ([...$this->subtotalRows, ...$this->totalRows] as $row) {
                    $isTotal = in_array($row, $this->totalRows, true);
                    $range = 'A'.$row.':'.$endColumn.$row;
                    $sheet->getStyle($range)->getFont()->setBold(true)->setSize($isTotal ? 12 : 11);
                    $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($isTotal ? 'D9C7A3' : 'F5ECDD');
                    $sheet->getStyle($range)->getAlignment()->setWrapText(false)->setVertical(Alignment::VERTICAL_CENTER);
                    $sheet->getRowDimension($row)->setRowHeight($isTotal ? 28 : 24);
                }
            }
            if ($this->sheetTitle === 'Daftar Kedai' && $this->withPhotos) {
                $sheet->getColumnDimension('A')->setWidth(11); // ±80 px: thumbnail 56 px + margin.
            }
            if ($this->sheetTitle === 'Penjelasan') {
                for ($row = 7; $row <= $lastRow; $row++) {
                    $sheet->getRowDimension($row)->setRowHeight(52);
                }
            }
            $statusFills = ['fast' => 'E4F0E8', 'stable' => 'E7EEF3', 'slow' => 'FFF1D8', 'problem' => 'F8E3E0', 'inactive' => 'EAE7E3', 'insufficient' => 'F1EBF6'];
            foreach ($this->labelCells as $cell => $key) {
                $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($statusFills[$key] ?? 'F5ECDD');
                $sheet->getStyle($cell)->getFont()->setBold(true);
            }
            $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(6, 6)->setFitToWidth(1)->setFitToHeight(0);
            // HARUS terakhir: Worksheet::getStyle() memindahkan seleksi aktif ke sel yang distyle (mis. sel label),
            // sehingga sel aktif awal bocor ke C7/C8 alih-alih sel freeze.
            $sheet->setSelectedCell($freezeCell);
        }];
    }
}
