<?php

namespace Tests\Feature\Owner;

use App\Exports\KioskInsightsExport;
use Illuminate\Support\Facades\File;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/** Database-free workbook regression tests for the plain-language report. */
class KioskInsightsExcelV2Test extends TestCase
{
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }
        Cell::setValueBinder(new DefaultValueBinder);
        parent::tearDown();
    }

    private function row(array $overrides = []): array
    {
        return array_replace(array_fill_keys(array_keys(KioskInsightsExport::columns()), null), [
            'id' => 1, 'owner_id' => 10, 'business_name' => 'Usaha Uji', 'area' => 'Utara',
            'name' => 'Kedai Uji', 'owner_name' => 'Pemilik Uji', 'phone' => '0812345',
            'address' => 'Di sebelah pasar', 'maps_address' => 'Jalan Peta No. 10',
            'maps_url' => 'https://www.google.com/maps?q=-6.2,106.8',
            'type' => 'Konsinyasi', 'is_active' => true, 'price_per_pack' => 16000,
            'units_per_pack' => 20, 'default_qty_mika' => 3, 'settled_count' => 3,
            'sold_out_count' => 2, 'weekly_sales' => 24.5, 'sell_through_pct' => 81.2,
            'bs_pct' => 2.5, 'fresh_return_pct' => 16.3, 'revenue_total' => 240000,
            'revenue_30' => 180000, 'pending_packs' => 3, 'pending_value' => 48000,
            'receivable' => 0, 'receivable_age_days' => null, 'visit_count' => 4, 'visits_30' => 3,
            'label' => 'Laris', 'label_key' => 'fast', 'display_label_key' => 'fast',
            'priority' => 4, 'suggestions' => ['Periksa jatah.'], 'photo_status' => 'Tidak ada foto',
        ], $overrides);
    }

    private function workbook(array $rows, bool $photos = false, bool $allOwners = false): Spreadsheet
    {
        $data = [
            'generated_at' => '2026-11-18T09:30:00+07:00', 'as_of' => '2026-11-18',
            'business_name' => 'Usaha Uji', 'all_owners' => $allOwners, 'period_start' => null,
            'rules' => [
                'min_settlements' => 3, 'problem_bs_pct' => 10, 'debt_age_days' => 30,
                'labels' => ['insufficient' => 'Data belum cukup', 'fast' => 'Laris', 'stable' => 'Stabil',
                    'slow' => 'Lambat', 'problem' => 'Banyak rusak', 'debt' => 'Piutang lama',
                    'bs' => 'Banyak rusak', 'inactive' => 'Tidak aktif'],
                // Internal snapshot metadata must not leak jargon or raw config keys into Penjelasan.
                'methodology' => ['performance' => 'siklus settlement % BS'],
            ],
            'rows' => $rows,
        ];
        File::ensureDirectoryExists(storage_path('framework/testing'));
        $path = storage_path('framework/testing/insights-excel-v2-'.bin2hex(random_bytes(6)).'.xlsx');
        $this->temporaryFiles[] = $path;
        file_put_contents($path, Excel::raw(new KioskInsightsExport($data, $photos), ExcelFormat::XLSX));
        Cell::setValueBinder(new DefaultValueBinder);

        return IOFactory::load($path);
    }

    private function headings(Worksheet $sheet): array
    {
        return $sheet->rangeToArray('A6:'.$sheet->getHighestColumn().'6')[0];
    }

    private function cell(Worksheet $sheet, string $heading, int $row): Cell
    {
        $index = array_search($heading, $this->headings($sheet), true);
        $this->assertNotFalse($index, $sheet->getTitle().' has '.$heading);

        return $sheet->getCell(Coordinate::stringFromColumnIndex($index + 1).$row);
    }

    public function test_every_sheet_uses_everyday_names_without_changing_numeric_values(): void
    {
        $book = $this->workbook([$this->row()]);
        foreach ($book->getAllSheets() as $sheet) {
            $text = json_encode($sheet->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            foreach (['Siklus', 'siklus', '% Laku', '% laku', '% BS', 'Sisa Dibawa Pulang (%)', 'Laju Jual', 'Bermasalah', 'settlement', 'amount_paid', 'min_settlements'] as $obsolete) {
                $this->assertStringNotContainsString($obsolete, $text, $sheet->getTitle());
            }
        }
        $catalog = $book->getSheetByName('Daftar Kedai');
        foreach ([
            'Terjual (%)' => [0.812, '0.0%'],
            'Laku per Minggu (biji)' => [24.5, '#,##0.0'],
            'Rusak/Kedaluwarsa (%)' => [0.025, '0.0%'],
            'Belum Laku, Dibawa Pulang (%)' => [0.163, '0.0%'],
            'Sudah Ditagih (kali)' => [3, null],
            'Habis Saat Ditagih (kali)' => [2, null],
            'Mika di Kedai Sekarang' => [3, '#,##0'],
            'Nilai Mika di Kedai' => [48000, '"Rp" #,##0'],
        ] as $heading => [$value, $format]) {
            $cell = $this->cell($catalog, $heading, 8);
            $this->assertEqualsWithDelta($value, $cell->getValue(), 0.000001, $heading);
            $this->assertSame(DataType::TYPE_NUMERIC, $cell->getDataType(), $heading);
            if ($format !== null) {
                $this->assertSame($format, $cell->getStyle()->getNumberFormat()->getFormatCode());
            }
        }
        $explanation = json_encode($book->getSheetByName('Penjelasan')->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach (['Sudah Ditagih (kali)', 'Habis Saat Ditagih (kali)', 'Laku per Minggu (biji)', 'Terjual (%)', 'Rusak/Kedaluwarsa (%)', 'Belum Laku, Dibawa Pulang (%)', 'Uangnya belum lunas pun tetap dihitung', '2 dari 3 penagihan terakhir'] as $term) {
            $this->assertStringContainsString($term, $explanation);
        }
        $book->disconnectWorksheets();
    }

    public function test_map_address_and_https_hyperlink_survive_photo_and_owner_layouts(): void
    {
        foreach ([false, true] as $photos) {
            foreach ([false, true] as $allOwners) {
                $book = $this->workbook([$this->row()], $photos, $allOwners);
                foreach (['Daftar Kedai' => 8, 'Perlu Tindakan' => 7] as $title => $row) {
                    $sheet = $book->getSheetByName($title);
                    $headings = $this->headings($sheet);
                    $addressIndex = array_search('Alamat', $headings, true);
                    $this->assertSame('Google Maps', $headings[$addressIndex - 1]);
                    $this->assertSame('Alamat (dari Peta)', $headings[$addressIndex + 1]);
                    $this->assertSame('Di sebelah pasar', $this->cell($sheet, 'Alamat', $row)->getValue());
                    $this->assertSame('Jalan Peta No. 10', $this->cell($sheet, 'Alamat (dari Peta)', $row)->getValue());
                    $map = $this->cell($sheet, 'Google Maps', $row);
                    $this->assertSame('Buka Maps', $map->getValue());
                    $this->assertSame('https://www.google.com/maps?q=-6.2,106.8', $map->getHyperlink()->getUrl());
                    $this->assertSame($photos && $title === 'Daftar Kedai' ? 'C7' : 'B7', $sheet->getFreezePane());
                    $this->assertSame($allOwners, in_array('Usaha', $headings, true));
                }
                $book->disconnectWorksheets();
            }
        }
    }

    public function test_map_address_is_literal_text_and_unsafe_urls_are_not_clickable(): void
    {
        $urls = ['http://maps.google.com/?q=0,0', 'https://google.com.evil.test/maps', 'javascript:alert(1)', '=HYPERLINK("https://evil.test","x")'];
        $rows = [];
        foreach ($urls as $index => $url) {
            $rows[] = $this->row(['id' => $index + 1, 'name' => 'Kedai '.$index, 'maps_url' => $url,
                'maps_address' => '=HYPERLINK("https://evil.test","alamat")']);
        }
        $book = $this->workbook($rows);
        $sheet = $book->getSheetByName('Daftar Kedai');
        foreach ($urls as $index => $url) {
            $row = $index + 8;
            $map = $this->cell($sheet, 'Google Maps', $row);
            $this->assertSame($url, $map->getValue());
            $this->assertSame('', $map->getHyperlink()->getUrl());
            $this->assertSame(DataType::TYPE_STRING, $map->getDataType());
            $address = $this->cell($sheet, 'Alamat (dari Peta)', $row);
            $this->assertSame('=HYPERLINK("https://evil.test","alamat")', $address->getValue());
            $this->assertSame(DataType::TYPE_STRING, $address->getDataType());
        }
        $book->disconnectWorksheets();
    }

    public function test_missing_metrics_explain_few_collections_but_known_zero_is_preserved(): void
    {
        $missing = ['sell_through_pct' => null, 'weekly_sales' => null, 'bs_pct' => null, 'fresh_return_pct' => null];
        $rows = [
            $this->row(array_merge($missing, ['name' => 'Belum ditagih', 'settled_count' => 0, 'label_key' => 'insufficient', 'label' => 'Data belum cukup'])),
            $this->row(array_merge($missing, ['name' => 'Dua tagihan', 'settled_count' => 2, 'label_key' => 'insufficient', 'label' => 'Data belum cukup'])),
            $this->row(['name' => 'Nol diketahui', 'settled_count' => 1, 'sell_through_pct' => 0, 'weekly_sales' => 0, 'bs_pct' => 0, 'fresh_return_pct' => 0]),
            $this->row(array_merge($missing, ['name' => 'Cash', 'type' => 'Cash', 'settled_count' => 0])),
        ];
        $book = $this->workbook($rows);
        foreach (['Daftar Kedai' => 8, 'Perlu Tindakan' => 7] as $title => $firstRow) {
            $sheet = $book->getSheetByName($title);
            // Perlu Tindakan sorts equal-priority records by name, so locate each record by its name.
            $positions = [];
            for ($row = $firstRow; $row <= $sheet->getHighestRow(); $row++) {
                $positions[$this->cell($sheet, 'Nama Kedai', $row)->getValue() ?? ''] = $row;
            }
            foreach (['Terjual (%)', 'Laku per Minggu (biji)', 'Rusak/Kedaluwarsa (%)', 'Belum Laku, Dibawa Pulang (%)'] as $heading) {
                $this->assertSame('— (baru ditagih 0 kali)', $this->cell($sheet, $heading, $positions['Belum ditagih'])->getValue());
                $this->assertSame('— (baru ditagih 2 kali)', $this->cell($sheet, $heading, $positions['Dua tagihan'])->getValue());
                $known = $this->cell($sheet, $heading, $positions['Nol diketahui']);
                $this->assertEquals(0, $known->getValue());
                $this->assertSame(DataType::TYPE_NUMERIC, $known->getDataType());
                $this->assertSame('—', $this->cell($sheet, $heading, $positions['Cash'])->getValue());
            }
        }
        $book->disconnectWorksheets();
    }

    public function test_debt_and_damage_labels_are_distinct_in_records_and_summary(): void
    {
        $book = $this->workbook([
            $this->row(['name' => 'Piutang saja', 'label_key' => 'problem', 'display_label_key' => 'debt', 'label' => 'Piutang lama', 'receivable' => 48000, 'receivable_age_days' => 30, 'bs_pct' => 0]),
            $this->row(['name' => 'Rusak saja', 'label_key' => 'problem', 'display_label_key' => 'bs', 'label' => 'Banyak rusak', 'bs_pct' => 10]),
            $this->row(['name' => 'Keduanya', 'label_key' => 'problem', 'display_label_key' => 'debt', 'label' => 'Piutang lama', 'receivable' => 16000, 'receivable_age_days' => 45, 'bs_pct' => 20]),
        ]);
        $sheet = $book->getSheetByName('Daftar Kedai');
        foreach ([8 => 'Piutang lama', 9 => 'Banyak rusak', 10 => 'Piutang lama'] as $row => $label) {
            $cell = $this->cell($sheet, 'Label', $row);
            $this->assertSame($label, $cell->getValue());
            $this->assertSame('F8E3E0', $cell->getStyle()->getFill()->getStartColor()->getRGB());
        }
        $distribution = [];
        $collect = false;
        foreach ($book->getSheetByName('Ringkasan')->toArray() as $row) {
            if (($row[0] ?? '') === 'Distribusi Label') {
                $collect = true;

                continue;
            }
            if ($collect && ($row[0] ?? null) === null) {
                break;
            }
            if ($collect) {
                $distribution[$row[0]] = $row[1];
            }
        }
        $this->assertEquals(2, $distribution['Piutang lama'] ?? null);
        $this->assertEquals(1, $distribution['Banyak rusak'] ?? null);
        $this->assertArrayNotHasKey('Bermasalah', $distribution);
        $this->assertEquals(3, array_sum($distribution));
        $book->disconnectWorksheets();
    }
}
