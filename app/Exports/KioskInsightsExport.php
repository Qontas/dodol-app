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
            'maps_url' => ['Google Maps', 'text', 'Klik "Buka Maps" untuk melihat lokasi kedai. Hanya tautan Google Maps yang aman (HTTPS) yang dapat diklik.', 14],
            'address' => ['Alamat', 'text', 'Alamat atau petunjuk lokasi yang ditulis untuk kedai.', 40],
            'maps_address' => ['Alamat (dari Peta)', 'text', 'Alamat dari peta yang tersimpan untuk kedai; bisa berbeda dari petunjuk pada kolom Alamat.', 40],
            'label' => ['Label', 'text', 'Gambaran kondisi kedai. Piutang lama berarti ada tagihan lama yang belum dibayar; Banyak rusak berarti banyak barang rusak atau kedaluwarsa.', 18],
            'suggestions' => ['Saran Tindakan', 'list', 'Saran untuk diperiksa sebelum bertindak, bukan perubahan jatah otomatis. Kedai tanpa saran tidak masuk "Perlu Tindakan".', 46],
            'sell_through_pct' => ['Terjual (%)', 'pct', 'Dari titipan yang sudah ditagih, berapa persen bijinya terjual. Hanya untuk kedai konsinyasi.', 16],
            'weekly_sales' => ['Laku per Minggu (biji)', 'decimal', 'Perkiraan biji laku dalam 7 hari: total biji terjual dibagi total hari sejak dititipkan sampai ditagih, lalu dikali 7. Titipan yang tidak laku tetap dihitung; setiap titipan dihitung paling sedikit 1 hari.', 20],
            'pending_packs' => ['Mika di Kedai Sekarang', 'mika', 'Mika titipan yang belum ditagih saat laporan dibuat, termasuk titipan awal yang dicatat saat mulai memakai aplikasi. Bukan piutang.', 12],
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
            'visit_count' => ['Kunjungan', 'int', 'Jumlah kedatangan ke kedai dalam periode laporan. Pencatatan titipan awal bukan kunjungan.', 11],
            'visits_30' => ['Kunjungan 30 Hari', 'int', 'Kunjungan dalam 30 hari terakhir sampai tanggal acuan.', 11],
            'avg_visit_gap_days' => ['Rerata Jarak Kunjungan (hari)', 'decimal', 'Rata-rata jarak hari antar kunjungan. Dipakai untuk menilai kapan kedai perlu dikunjungi lagi.', 13],
            'delivered_packs' => ['Mika Diantar', 'mika', 'Jumlah mika diantar dalam periode.', 11],
            'sold_units' => ['Biji Terjual', 'decimal', 'Jumlah biji terjual dalam periode (termasuk cash).', 11],
            'bs_pct' => ['Rusak/Kedaluwarsa (%)', 'pct', 'Persentase biji yang rusak atau kedaluwarsa dari titipan konsinyasi yang sudah ditagih. Barang yang masih baik dan dibawa pulang tidak termasuk.', 22],
            'fresh_return_pct' => ['Belum Laku, Dibawa Pulang (%)', 'pct', 'Persentase biji yang belum laku, masih baik, dan dibawa pulang dari titipan konsinyasi yang sudah ditagih. Bukan kerugian dan tidak membuat label Banyak rusak.', 26],
            'revenue_total' => ['Omset Total', 'rp', 'Uang yang sudah diterima dari penagihan sepanjang riwayat pada perjalanan yang sudah selesai. Cicilan berikutnya menambah omset pada tanggal perjalanan penagihan asal, bukan tanggal cicilan dibayar.', 15],
            'revenue_30' => ['Omset 30 Hari', 'rp', 'Uang yang sudah diterima dari penagihan pada perjalanan dalam 30 hari terakhir. Tanggal yang dipakai adalah tanggal perjalanan penagihan, bukan tanggal cicilan dibayar.', 15],
            'avg_revenue_per_visit' => ['Omset / Kunjungan', 'rp', 'Rata-rata uang diterima per kunjungan.', 15],
            'sold_out_count' => ['Habis Saat Ditagih (kali)', 'int', 'Berapa kali titipan konsinyasi terjual sedikitnya 95% saat ditagih. Jadi hampir habis juga dihitung.', 22],
            'settled_count' => ['Sudah Ditagih (kali)', 'int', 'Berapa kali titipan konsinyasi sudah diperiksa dan ditagih, termasuk yang uangnya belum lunas.', 20],
            'pending_age_days' => ['Umur Titipan (hari)', 'days', 'Sudah berapa hari titipan tertua berada di kedai dan belum ditagih.', 11],
        ];
    }

    /** Subset kolom sheet "Perlu Tindakan" (urutan mengikuti columns()). */
    private const ACTION_COLUMNS = [
        'name', 'business_name', 'area', 'maps_url', 'address', 'maps_address', 'label', 'suggestions', 'sell_through_pct', 'weekly_sales',
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
        $this->rows[] = ['Rerata Terjual (%)', $s['avg_sell_through_pct'] === null ? '—' : $s['avg_sell_through_pct'] / 100, 'Rata-rata Terjual (%) dari kedai konsinyasi yang memiliki data. Setiap kedai dihitung sama, bukan jumlah biji laku per minggu.'];
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
            $this->section([$title, 'Hanya kedai konsinyasi yang sudah ditagih sedikitnya '.($this->data['rules']['min_settlements'] ?? 3).' kali; tidak termasuk Tidak aktif dan Data belum cukup. Urut menurut Laku per Minggu (biji).']);
            $this->section(['Usaha', 'Area', 'Kedai', 'Laku per Minggu (biji)', 'Terjual (%)', 'Sudah Ditagih (kali)', 'Label']);
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
                    $this->labelCells[$cell] = $r['display_label_key'] ?? $r['label_key'] ?? 'insufficient';
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
                if ($value === null && in_array($key, ['sell_through_pct', 'weekly_sales', 'bs_pct', 'fresh_return_pct'], true)) {
                    $count = (int) ($r['settled_count'] ?? 0);
                    $value = ($r['type'] ?? '') === 'Konsinyasi' && $count < ($this->data['rules']['min_settlements'] ?? 3)
                        ? KioskInsightsSummary::missingMetric($r)
                        : '—';
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
        $rules = $this->data['rules'] ?? [];
        foreach ([
            ['Minimal sudah ditagih', ($rules['min_settlements'] ?? 3).' kali sebelum menilai penjualan kedai.'],
            ['Lambat', 'Terjual kurang dari '.($rules['slow_sell_through_pct'] ?? 60).'%.'],
            ['Laris', 'Terjual sedikitnya '.($rules['fast_sell_through_pct'] ?? 80).'% dan Laku per Minggu berada pada peringkat sedikitnya '.($rules['fast_percentile'] ?? 75).' dari 100 di antara kedai konsinyasi yang cukup data dalam usaha yang sama.'],
            ['Banyak rusak', 'Rusak/Kedaluwarsa sedikitnya '.($rules['problem_bs_pct'] ?? 10).'%.'],
            ['Piutang lama', 'Ada piutang yang belum dibayar selama sedikitnya '.($rules['debt_age_days'] ?? 30).' hari.'],
            ['Tidak aktif', 'Kedai dinonaktifkan atau sudah lebih dari '.($rules['inactive_days'] ?? 30).' hari tidak dikunjungi.'],
            ['Pengingat kunjungan', 'Setelah '.($rules['revisit_gap_multiplier'] ?? 1.5).' kali jarak kunjungan biasanya, paling cepat '.($rules['revisit_min_days'] ?? 10).' hari.'],
            ['Kunjungan dengan sedikit riwayat', 'Jika belum ada '.($rules['revisit_min_gaps'] ?? 3).' jarak antarkunjungan yang bisa dihitung, ingatkan setelah '.($rules['revisit_days'] ?? 14).' hari.'],
            ['Batas saran tambahan jatah', 'Paling banyak '.min(2, max(0, $rules['max_quota_increase'] ?? 2)).' mika; tidak mengubah jatah otomatis.'],
            ['Zona waktu laporan', $rules['timezone'] ?? 'Asia/Jakarta'],
        ] as $row) {
            $this->rows[] = $row;
        }
        $this->rows[] = [null];
        $this->section(['Kolom', 'Arti']);
        $this->rows[] = ['Foto', 'Tampil jika memilih Export Excel + Foto. Kedai tanpa foto tertulis "Tidak ada foto"; foto yang tidak bisa dibuka tertulis "Foto gagal dimuat" dan laporan tetap bisa digunakan.'];
        foreach (self::columns() as [$heading, , $definition]) {
            $this->rows[] = [$heading, $definition];
        }
    }

    public static function methodology(array $data): array
    {
        $r = $data['rules'] ?? [];

        return [
            'Cakupan' => 'Nama, alamat, harga, jatah, status aktif, mika di kedai dan piutang menunjukkan keadaan saat laporan dibuat. Penjualan dan kunjungan mengikuti periode laporan sampai tanggal acuan.',
            'Performa' => 'Penilaian penjualan hanya untuk kedai Konsinyasi, bukan Cash atau Booking. Tanda — berarti tidak berlaku atau belum ada data. Angka 0 berarti sudah ada data dan nilainya memang nol. Jika baru sedikit ditagih, angka yang sudah tersedia tetap ditampilkan, tetapi belum dipakai untuk menilai kedai.',
            'Terjual (%)' => 'Dari titipan konsinyasi yang sudah ditagih, berapa persen bijinya terjual: jumlah biji terjual dibagi jumlah biji dititipkan, lalu dikali 100.',
            'Sudah Ditagih (kali)' => 'Berapa kali titipan konsinyasi sudah diperiksa dan ditagih. Uangnya belum lunas pun tetap dihitung. Penilaian kedai perlu sedikitnya '.($r['min_settlements'] ?? 3).' kali ditagih; titipan yang belum ditagih tidak ikut dihitung.',
            'Laku per Minggu (biji)' => 'Jumlah biji terjual dibagi jumlah hari dari dititipkan sampai ditagih, lalu dikali 7. Setiap titipan dihitung paling sedikit 1 hari; yang tidak laku tetap dihitung. Daftar 10 paling laris dan 10 paling lambat laku diurutkan menurut angka ini, bukan omset. Kedai Tidak aktif dan Data belum cukup tidak ikut peringkat.',
            'Habis Saat Ditagih (kali)' => 'Berapa kali titipan konsinyasi terjual sedikitnya 95% saat ditagih. Hampir habis juga dihitung, bukan harus habis sama sekali.',
            'Rusak/Kedaluwarsa (%)' => 'Biji yang rusak atau kedaluwarsa dibagi biji titipan konsinyasi yang sudah ditagih, lalu dikali 100. Barang yang masih baik dan dibawa pulang tidak termasuk.',
            'Belum Laku, Dibawa Pulang (%)' => 'Biji yang belum laku, masih baik, dan dibawa pulang dibagi biji titipan konsinyasi yang sudah ditagih, lalu dikali 100. Ini bukan kerugian dan tidak membuat label Banyak rusak.',
            'Label' => 'Urutan penilaian: Tidak aktif, Data belum cukup, Piutang lama atau Banyak rusak, Laris, Lambat, lalu Stabil. Piutang lama berarti ada uang belum dibayar selama sedikitnya '.($r['debt_age_days'] ?? 30).' hari. Banyak rusak berarti Rusak/Kedaluwarsa mencapai '.($r['problem_bs_pct'] ?? 10).'% atau lebih. Jika keduanya terjadi, label Piutang lama ditampilkan dan saran memuat keduanya. Lambat berarti Terjual kurang dari '.($r['slow_sell_through_pct'] ?? 60).'%. Laris berarti Terjual sedikitnya '.($r['fast_sell_through_pct'] ?? 80).'% dan Laku per Minggu berada pada peringkat sedikitnya '.($r['fast_percentile'] ?? 75).' dari 100 di antara kedai konsinyasi yang cukup data dalam usaha yang sama. Tidak aktif berarti dinonaktifkan atau sudah lebih dari '.($r['inactive_days'] ?? 30).' hari tidak dikunjungi.',
            'Kunjungan' => 'Titipan awal yang dicatat saat mulai memakai aplikasi tetap masuk mika di kedai, tetapi pencatatannya bukan kunjungan ke kedai.',
            'Saran "Kunjungi"' => 'Pengingat mengikuti kebiasaan kunjungan kedai: setelah '.($r['revisit_gap_multiplier'] ?? 1.5).' kali jarak kunjungan biasanya, paling cepat '.($r['revisit_min_days'] ?? 10).' hari. Kedai yang biasa dikunjungi lebih sering akan diingatkan lebih cepat. Jika belum ada '.($r['revisit_min_gaps'] ?? 3).' jarak antarkunjungan yang bisa dihitung, pengingat muncul setelah '.($r['revisit_days'] ?? 14).' hari.',
            'Subtotal dan total' => 'Di Daftar Kedai, baris SUBTOTAL menjumlahkan mika dan nilainya untuk satu area. Baris TOTAL tebal ada paling bawah. Di Perlu Tindakan, subtotal per area berada di bawah daftar. Angka ini hanya menjumlahkan kedai yang tampil pada lembar tersebut dan tidak berubah saat disaring. Baris subjudul, SUBTOTAL dan TOTAL bukan kedai: jangan ikutkan saat mengurutkan data.',
            'Titipan vs piutang' => 'Mika di kedai adalah barang yang belum ditagih, bukan uang yang harus dibayar. Piutang adalah uang yang sudah ditagih tetapi belum dibayar. Jangan menjumlahkan keduanya sebagai omset.',
            'Omset' => 'Uang yang sudah diterima dicatat pada tanggal perjalanan penagihan yang sudah selesai. Jika ada cicilan berikutnya, omset pada tanggal penagihan asal bertambah. Jadi ini bukan daftar uang masuk menurut tanggal pembayaran. Penjualan langsung tanpa kedai tidak masuk laporan ini.',
            'Saran' => 'Saran tidak otomatis mengubah jatah. Kedai Data belum cukup belum diberi saran terkait barang rusak atau jatah, tetapi tetap bisa diingatkan untuk ditagih atau dikunjungi. Jika sedikitnya 95% terjual pada 2 dari 3 penagihan terakhir, barang mungkin keburu habis sebelum pembeli berikutnya datang: saran tambahan dimulai dari 1 mika. Perkiraan biji laku per hari dikali jarak kunjungan, lalu dibagi isi per mika, bisa memberi tambahan lebih besar; batasnya '.min(2, max(0, $r['max_quota_increase'] ?? 2)).' mika. Tidak ada saran kenaikan untuk kedai Piutang lama, Banyak rusak, atau Tidak aktif. Kedai tanpa saran tidak masuk Perlu Tindakan. Periksa keadaan kedai sebelum bertindak.',
            'Filter Excel' => 'Daftar Kedai dikelompokkan per area. Baris subjudul berwarna bukan data kedai. Untuk menyaring kedai, gunakan kolom Area yang diulang pada setiap baris kedai. Perlu Tindakan diurutkan dari piutang lama, lama tidak dikunjungi, banyak barang rusak, lalu saran jatah.',
            'Alamat dan peta' => 'Alamat berisi petunjuk yang ditulis untuk kedai. Alamat (dari Peta) berisi alamat peta yang tersimpan. Klik Buka Maps untuk melihat lokasi; jika titik koordinat tersedia, lokasi mengarah ke titik tersebut.',
            'Keamanan' => 'Teks ditampilkan apa adanya, bukan dijalankan sebagai rumus Excel. Hanya tautan Google Maps yang aman (HTTPS) yang dapat diklik. Foto memakai salinan kecil yang sudah disiapkan; foto gagal tidak membatalkan laporan.',
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
            $statusFills = ['fast' => 'E4F0E8', 'stable' => 'E7EEF3', 'slow' => 'FFF1D8', 'problem' => 'F8E3E0', 'debt' => 'F8E3E0', 'bs' => 'F8E3E0', 'inactive' => 'EAE7E3', 'insufficient' => 'F1EBF6'];
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
