<?php

namespace Tests\Feature\Owner;

use App\Exports\KioskInsightsExport;
use App\Support\KioskInsightsPdf;
use App\Support\KioskInsightsSummary;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/** Rendering is database-free; no RefreshDatabase and no shared test fixtures. */
class KioskInsightsRenderTest extends TestCase
{
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }
        Cell::setValueBinder(new DefaultValueBinder);
        parent::tearDown();
    }

    private function temporaryFile(string $extension): string
    {
        File::ensureDirectoryExists(storage_path('framework/testing'));
        $path = storage_path('framework/testing/insights-'.bin2hex(random_bytes(6)).'.'.$extension);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function row(array $overrides = []): array
    {
        $r = array_fill_keys(array_keys(KioskInsightsExport::columns()), null);

        return array_replace($r, [
            'id' => 1, 'owner_id' => 10, 'business_name' => 'Kuali Stories', 'area' => 'Utara',
            'name' => 'Kedai Uji', 'owner_name' => 'Ibu Uji', 'phone' => '0812345', 'address' => 'Alamat uji',
            'maps_url' => 'https://maps.google.com/?q=0,0', 'type' => 'Konsinyasi', 'price_label' => 'Standar',
            'price_per_pack' => 16000, 'units_per_pack' => 20, 'default_qty_mika' => 3, 'is_active' => true,
            'first_titip_date' => '2026-10-01', 'last_visit_at' => '2026-11-15 09:00:00', 'last_operator' => 'Operator',
            'visit_count' => 5, 'visits_30' => 4, 'settled_count' => 3, 'weekly_sales' => 24.5, 'sell_through_pct' => 81.2,
            'revenue_total' => 240000, 'revenue_30' => 180000, 'pending_packs' => 3, 'pending_value' => 48000,
            'receivable' => 0, 'label' => 'Laris', 'label_key' => 'fast', 'priority' => 5,
            'suggestions' => ['Pertahankan kunjungan rutin.'], 'photo_status' => 'Tidak ada foto', 'thumbnail_path' => null,
        ], $overrides);
    }

    private function data(array $rows): array
    {
        return ['generated_at' => '2026-11-16T09:30:00+07:00', 'as_of' => '2026-11-16', 'business_name' => 'Kuali Stories',
            'all_owners' => false, 'period_start' => null,
            'rules' => ['min_settlements' => 3, 'labels' => ['fast' => 'Laris']], 'rows' => $rows];
    }

    private function workbook(array $data, bool $photos = false): Spreadsheet
    {
        $path = $this->temporaryFile('xlsx');
        file_put_contents($path, Excel::raw(new KioskInsightsExport($data, $photos), ExcelFormat::XLSX));
        // Reader uses PhpSpreadsheet's process-global binder too; do not reinterpret numeric XML as text.
        Cell::setValueBinder(new DefaultValueBinder);

        return IOFactory::load($path);
    }

    public function test_workbook_has_four_professional_sheets_and_literal_untrusted_text(): void
    {
        $data = $this->data([$this->row(['name' => '=HYPERLINK("https://evil.test","x")',
            'phone' => '+628123', 'notes' => '@SUM(1,1)', 'suggestions' => ['-2+3'], 'maps_url' => 'javascript:alert(1)'])]);
        $data['business_name'] = '=1+1';
        $book = $this->workbook($data);
        $this->assertSame(['Ringkasan', 'Perlu Tindakan', 'Daftar Kedai', 'Penjelasan'], $book->getSheetNames());
        foreach ($book->getAllSheets() as $sheet) {
            foreach ($sheet->getCoordinates() as $coordinate) {
                $this->assertNotSame(DataType::TYPE_FORMULA, $sheet->getCell($coordinate)->getDataType());
            }
        }
        $catalog = $book->getSheetByName('Daftar Kedai');
        $headings = $catalog->rangeToArray('A6:'.$catalog->getHighestColumn().'6')[0];
        $column = fn (string $heading): string => Coordinate::stringFromColumnIndex(array_search($heading, $headings, true) + 1);
        $this->assertSame('AREA', $catalog->getCell('A7')->getValue());
        $this->assertSame('DATA', $catalog->getCell('A8')->getValue());
        $this->assertSame('+628123', $catalog->getCell($column('Telepon').'8')->getValue());
        $this->assertEquals(16000, $catalog->getCell($column('Harga / Mika').'8')->getValue());
        $this->assertSame(DataType::TYPE_NUMERIC, $catalog->getCell($column('Harga / Mika').'8')->getDataType());
        $this->assertEquals(0.812, $catalog->getCell($column('Sell-through').'8')->getValue());
        $this->assertIsNumeric($catalog->getCell($column('Titip Pertama').'8')->getValue());
        $this->assertSame('', $catalog->getCell($column('Google Maps').'8')->getHyperlink()->getUrl());
        $this->assertSame('D7', $catalog->getFreezePane());
        $this->assertStringStartsWith('A6:', $catalog->getAutoFilter()->getRange());
        $this->assertCount(0, $catalog->getDrawingCollection());
        $book->disconnectWorksheets();
    }

    public function test_photos_embed_only_valid_local_jpeg_and_corruption_falls_back(): void
    {
        $jpeg = $this->temporaryFile('jpg');
        $image = imagecreatetruecolor(20, 20);
        imagejpeg($image, $jpeg);
        imagedestroy($image);
        $broken = $this->temporaryFile('jpg');
        file_put_contents($broken, 'not an image');
        $book = $this->workbook($this->data([
            $this->row(['thumbnail_path' => $jpeg, 'photo_status' => 'ok']),
            $this->row(['id' => 2, 'thumbnail_path' => $broken, 'photo_status' => 'ok']),
        ]), true);
        $sheet = $book->getSheetByName('Daftar Kedai');
        $this->assertCount(1, $sheet->getDrawingCollection());
        $this->assertSame('Foto gagal dimuat', $sheet->getCell('B9')->getValue());
        $this->assertSame(45.0, $sheet->getRowDimension(8)->getRowHeight());
        $this->assertEquals(8.57, $sheet->getColumnDimension('B')->getWidth());
        $this->assertNull(KioskInsightsSummary::thumbnail(['photo_status' => 'ok', 'thumbnail_path' => 'https://example.test/a.jpg']));
        $book->disconnectWorksheets();
    }

    public function test_summary_keeps_owners_separate_and_rankings_exclude_ineligible_kiosks(): void
    {
        $summary = KioskInsightsSummary::summarize($this->data([
            $this->row(), $this->row(['id' => 2, 'owner_id' => 20, 'business_name' => 'Usaha Dua', 'weekly_sales' => 12]),
            $this->row(['id' => 3, 'type' => 'Cash', 'weekly_sales' => 9999, 'sell_through_pct' => null]),
            $this->row(['id' => 4, 'settled_count' => 2, 'weekly_sales' => 8888]),
        ]));
        $this->assertCount(2, $summary['areas']);
        $this->assertSame([1, 2], array_column($summary['top10'], 'id'));
        $this->assertSame([2, 1], array_column($summary['slow10'], 'id'));
        $this->assertSame(4, $summary['totals']['kiosks']);
    }

    public function test_pdf_generates_grouped_six_card_pages_without_emoji_or_remote_resources(): void
    {
        $rows = [];
        for ($i = 1; $i <= 7; $i++) {
            $rows[] = $this->row(['id' => $i, 'name' => 'Kedai '.$i.' ⭐', 'label' => 'Laris 🌟']);
        }
        $data = $this->data($rows);
        $pdf = KioskInsightsPdf::render($data);
        $this->assertStringStartsWith('%PDF-', $pdf->output());
        $this->assertGreaterThanOrEqual(3, $pdf->getDomPDF()->getCanvas()->get_page_count());
        $this->assertSame('Kedai 1 ', KioskInsightsSummary::pdfText('Kedai 1 ⭐'));
        $this->assertSame('Laris ', KioskInsightsSummary::pdfText('Laris 🌟'));
        $this->assertSame('Laporan Kedai KualiStories 16 Nov 2026.pdf', KioskInsightsPdf::filename($data));
        $this->assertStringNotContainsString('/', KioskInsightsPdf::filename(array_replace($data, ['business_name' => '../../x'])));
        $fragment = KioskInsightsPdf::renderPart($this->data(array_slice($rows, 0, 6)));
        $this->assertSame(1, $fragment->getDomPDF()->getCanvas()->get_page_count(), 'Six normal-length cards fit one A4 page.');
        $this->assertStringNotContainsString('Ringkasan usaha', $fragment->getDomPDF()->getDom()->saveHTML());
    }

    public function test_summary_only_part_accepts_full_dataset_but_renders_no_cards(): void
    {
        $data = $this->data(array_fill(0, 40, $this->row(['name' => 'UniqueCardName'])));
        $data['summary_only'] = true;
        $captured = [];
        View::composer('exports.kiosk-insights-pdf', function ($view) use (&$captured): void {
            $captured = $view->getData();
        });
        $pdf = KioskInsightsPdf::renderPart($data, true);
        $html = view('exports.kiosk-insights-pdf', $captured)->render();
        $this->assertStringContainsString('Ringkasan usaha', $html);
        $this->assertStringNotContainsString('class="card"', $html);
        $this->assertStringStartsWith('%PDF-', $pdf->output());
    }

    public function test_maps_hyperlinks_allow_only_https_maps_hosts(): void
    {
        $this->assertSame('https://maps.app.goo.gl/abcd', KioskInsightsSummary::mapsUrl('https://maps.app.goo.gl/abcd'));
        foreach (['http://maps.google.com/a', 'https://evil.test/maps', 'https://google.com.evil.test/maps', 'https://user@maps.google.com/a', 'file:///tmp/x', '=1+1'] as $url) {
            $this->assertNull(KioskInsightsSummary::mapsUrl($url));
        }
    }
}
