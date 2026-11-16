<?php

namespace Tests\Feature\Owner;

use App\Exports\KioskInsightsExport;
use App\Support\IdDate;
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
        // Baris 7 = subjudul area (satu sel di kolom pertama), baris 8 = data kedai.
        $this->assertSame('Utara', $catalog->getCell('A7')->getValue());
        $this->assertSame('+628123', $catalog->getCell($column('Telepon').'8')->getValue());
        $this->assertEquals(16000, $catalog->getCell($column('Harga / Mika').'8')->getValue());
        $this->assertSame(DataType::TYPE_NUMERIC, $catalog->getCell($column('Harga / Mika').'8')->getDataType());
        $this->assertEquals(0.812, $catalog->getCell($column('% Laku').'8')->getValue());
        $this->assertIsNumeric($catalog->getCell($column('Titip Pertama').'8')->getValue());
        $this->assertSame('', $catalog->getCell($column('Google Maps').'8')->getHyperlink()->getUrl());
        $this->assertSame('B7', $catalog->getFreezePane(), 'Header + kolom Nama Kedai (tanpa foto) beku.');
        $this->assertStringStartsWith('A6:', $catalog->getAutoFilter()->getRange());
        $this->assertCount(0, $catalog->getDrawingCollection());
        $book->disconnectWorksheets();
    }

    public function test_saved_workbook_panes_start_and_select_at_the_freeze_cell(): void
    {
        $titles = ['Ringkasan', 'Perlu Tindakan', 'Daftar Kedai', 'Penjelasan'];
        foreach ([false, true] as $photos) {
            foreach ([false, true] as $allOwners) {
                $data = $this->data([$this->row()]);
                $data['all_owners'] = $allOwners;
                $path = $this->temporaryFile('xlsx');
                file_put_contents($path, Excel::raw(new KioskInsightsExport($data, $photos), ExcelFormat::XLSX));
                Cell::setValueBinder(new DefaultValueBinder);
                $zip = new \ZipArchive;
                $this->assertTrue($zip->open($path));
                foreach ($titles as $index => $title) {
                    $expected = $photos && $title === 'Daftar Kedai' ? 'C7' : 'B7';
                    $xml = simplexml_load_string($zip->getFromName('xl/worksheets/sheet'.($index + 1).'.xml'));
                    $view = $xml->sheetViews->sheetView;
                    $pane = $view->pane->attributes();
                    $label = $title.($photos ? ' +foto' : '').($allOwners ? ' semua-owner' : '');
                    $this->assertSame('frozen', (string) $pane['state'], $label);
                    $this->assertSame('6', (string) $pane['ySplit'], $label);
                    // Pane bawah/kanan harus mulai TEPAT di sel freeze, bukan A1 (yang menampilkan baris 1–6 dobel).
                    $this->assertSame($expected, (string) $pane['topLeftCell'], 'topLeftCell '.$label);
                    $this->assertSame('bottomRight', (string) $pane['activePane'], $label);
                    // Sel aktif awal di pane aktif juga di sel freeze.
                    $active = collect($view->selection)->first(fn ($s) => (string) $s['pane'] === 'bottomRight');
                    $this->assertNotNull($active, 'selection bottomRight '.$label);
                    $this->assertSame($expected, (string) $active['activeCell'], 'activeCell '.$label);
                    $this->assertSame($expected, (string) $active['sqref'], 'sqref '.$label);
                }
                $zip->close();
            }
        }
    }

    public function test_saved_workbook_titles_are_complete_and_span_the_visible_columns(): void
    {
        foreach ([false, true] as $photos) {
            foreach ([[], [$this->row()]] as $rows) {
                $book = $this->workbook($this->data($rows), $photos);
                foreach ($book->getAllSheets() as $sheet) {
                    $this->assertSame('LAPORAN KEDAI — '.$sheet->getTitle(), $sheet->getCell('A1')->getValue());
                    $range = 'A1:'.$sheet->getHighestColumn().'1';
                    $this->assertSame([$range => $range], $sheet->getMergeCells(), $sheet->getTitle());
                    $this->assertFalse($sheet->getStyle('A1')->getAlignment()->getWrapText());
                }
                $book->disconnectWorksheets();
            }
        }
    }

    /** @return list<string> judul kolom baris header (baris 6) sheet tertentu. */
    private function headings(Spreadsheet $book, string $sheet): array
    {
        $ws = $book->getSheetByName($sheet);

        return array_values(array_filter($ws->rangeToArray('A6:'.$ws->getHighestColumn().'6')[0], fn ($h) => $h !== null && $h !== ''));
    }

    public function test_columns_are_reader_ordered_and_technical_columns_removed(): void
    {
        $book = $this->workbook($this->data([$this->row()]), true);
        $headings = $this->headings($book, 'Daftar Kedai');

        // Urutan pembaca: Foto, Nama, Area, Label, Saran, % Laku, Laju, Stok, Nilai, Piutang, Umur, Kunjungan terakhir…
        $this->assertSame(['Foto', 'Nama Kedai', 'Area', 'Label', 'Saran Tindakan', '% Laku', 'Laju Jual (biji/minggu)',
            'Stok Titipan (mika)', 'Nilai Titipan', 'Piutang', 'Umur Piutang (hari)', 'Kunjungan Terakhir', 'Operator Terakhir',
            'Hari Sejak Kunjungan'], array_slice($headings, 0, 14));
        // Lalu identitas, lalu metrik detail.
        $this->assertLessThan(array_search('Sisa Dibawa Pulang (%)', $headings, true), array_search('Pemilik Kedai', $headings, true));
        $this->assertLessThan(array_search('Operator Terbanyak', $headings, true), array_search('Alamat', $headings, true));
        foreach (['Jenis Baris', 'ID Kedai', 'ID Owner', 'Kode Label', 'Prioritas', 'Status Foto', 'Biji Laku Konsinyasi',
            'Biji Titipan Selesai', 'Usaha', 'Sell-through'] as $removed) {
            $this->assertNotContains($removed, $headings, "Kolom '{$removed}' tidak boleh ada pada mode satu owner.");
        }
        foreach ($this->headings($book, 'Perlu Tindakan') as $heading) {
            $this->assertNotContains($heading, ['Jenis Baris', 'ID Kedai', 'Prioritas', 'Kode Label']);
        }
        $book->disconnectWorksheets();
    }

    public function test_bs_and_fresh_returns_have_separate_numeric_columns_and_explanations(): void
    {
        $book = $this->workbook($this->data([$this->row(['bs_pct' => 10, 'fresh_return_pct' => 40])]));
        foreach (['Daftar Kedai' => 8, 'Perlu Tindakan' => 7] as $name => $row) {
            $sheet = $book->getSheetByName($name);
            $headings = $this->headings($book, $name);
            $this->assertNotContains('Retur / BS', $headings);
            foreach (['% BS' => 0.1, 'Sisa Dibawa Pulang (%)' => 0.4] as $heading => $value) {
                $cell = Coordinate::stringFromColumnIndex(array_search($heading, $headings, true) + 1).$row;
                $this->assertEquals($value, $sheet->getCell($cell)->getValue());
                $this->assertSame('0.0%', $sheet->getStyle($cell)->getNumberFormat()->getFormatCode());
            }
        }
        $text = json_encode($book->getSheetByName('Penjelasan')->toArray(), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('KIOSK_INSIGHTS_BS_PCT', $text);
        $this->assertStringContainsString('permintaan tersensor', $text);
        $book->disconnectWorksheets();
    }

    public function test_owner_column_only_appears_in_all_owners_mode(): void
    {
        $single = $this->workbook($this->data([$this->row()]));
        $this->assertNotContains('Usaha', $this->headings($single, 'Daftar Kedai'));
        $single->disconnectWorksheets();

        $all = $this->data([$this->row(), $this->row(['id' => 2, 'owner_id' => 20, 'business_name' => 'Usaha Dua'])]);
        $all['all_owners'] = true;
        $book = $this->workbook($all);
        $headings = $this->headings($book, 'Daftar Kedai');
        $this->assertContains('Usaha', $headings);
        $this->assertSame('Kuali Stories — Utara', $book->getSheetByName('Daftar Kedai')->getCell('A7')->getValue());
        $book->disconnectWorksheets();
    }

    public function test_mika_columns_are_whole_numbers_and_area_rows_are_styled_subheadings(): void
    {
        $book = $this->workbook($this->data([$this->row(['default_qty_mika' => 3, 'delivered_packs' => 12.0, 'pending_packs' => 5.0,
            'receivable_age_days' => 7.0, 'days_since_visit' => 1.0])]));
        $sheet = $book->getSheetByName('Daftar Kedai');
        $headings = $this->headings($book, 'Daftar Kedai');
        foreach (['Jatah Titipan (mika)', 'Mika Diantar', 'Stok Titipan (mika)'] as $heading) {
            $cell = Coordinate::stringFromColumnIndex(array_search($heading, $headings, true) + 1).'8';
            $this->assertSame('#,##0', $sheet->getStyle($cell)->getNumberFormat()->getFormatCode(), $heading);
            $this->assertSame((int) $sheet->getCell($cell)->getValue(), $sheet->getCell($cell)->getValue(), $heading.' bulat');
        }
        // Subjudul area: satu sel teks, bergaya (tebal + warna), tanpa merge.
        $this->assertTrue($sheet->getStyle('A7')->getFont()->getBold());
        $this->assertSame('E9DCC6', $sheet->getStyle('A7')->getFill()->getStartColor()->getRGB());
        $this->assertSame(['A1:'.$sheet->getHighestColumn().'1'], array_values($sheet->getMergeCells()), 'Hanya judul A1 yang digabung; baris area dan data tidak.');
        $book->disconnectWorksheets();
    }

    public function test_metadata_uses_indonesian_wib_format_not_iso_utc(): void
    {
        // 11:18 UTC = 18:18 WIB.
        $data = $this->data([$this->row()]);
        $data['generated_at'] = '2026-11-16T11:18:00+00:00';
        $data['period_start'] = '2026-07-19';
        $meta = KioskInsightsSummary::meta($data);
        $this->assertSame('16 Nov 2026, 18:18 WIB', $meta['generated']);
        $this->assertSame('16 Nov 2026', $meta['as_of']);
        $this->assertSame('19 Jul 2026 – 16 Nov 2026', $meta['period']);
        $this->assertSame('1 Jan 2027, 06:30 WIB', IdDate::waktu('2026-12-31T23:30:00+00:00', 'Asia/Jakarta'));
        $this->assertSame('16 Nov 2026, 19:18 WITA', IdDate::waktu('2026-11-16T11:18:00+00:00', 'Asia/Makassar'));

        $book = $this->workbook($data);
        $summary = $book->getSheetByName('Ringkasan');
        $this->assertSame('16 Nov 2026, 18:18 WIB', $summary->getCell('D3')->getValue());
        $this->assertSame('16 Nov 2026', $summary->getCell('B3')->getValue());
        $this->assertSame('19 Jul 2026 – 16 Nov 2026', $summary->getCell('B4')->getValue());
        $this->assertStringNotContainsString('T11:18', json_encode($summary->toArray()));
        $book->disconnectWorksheets();
    }

    public function test_rankings_exclude_inactive_and_insufficient_and_use_new_titles(): void
    {
        $rows = [
            $this->row(['id' => 1, 'name' => 'Laris Aktif', 'weekly_sales' => 50]),
            $this->row(['id' => 2, 'name' => 'Nonaktif Cepat', 'weekly_sales' => 999, 'label_key' => 'inactive', 'label' => 'Tidak aktif']),
            $this->row(['id' => 3, 'name' => 'Kurang Data Lambat', 'weekly_sales' => 0.1, 'label_key' => 'insufficient', 'label' => 'Data belum cukup']),
            $this->row(['id' => 4, 'name' => 'Lambat Aktif', 'weekly_sales' => 5, 'label_key' => 'slow', 'label' => 'Lambat']),
        ];
        $summary = KioskInsightsSummary::summarize($this->data($rows));
        $this->assertSame([1, 4], array_column($summary['top10'], 'id'));
        $this->assertSame([4, 1], array_column($summary['slow10'], 'id'));

        $book = $this->workbook($this->data($rows));
        $text = json_encode($book->getSheetByName('Ringkasan')->toArray());
        $this->assertStringContainsString('10 Kedai Paling Laris', $text);
        $this->assertStringContainsString('10 Kedai Paling Lambat Laku', $text);
        $this->assertStringNotContainsString('Tercepat', $text);
        $this->assertStringNotContainsString('Terlambat', $text);
        $this->assertStringNotContainsString('Nonaktif Cepat', $text);
        $this->assertStringNotContainsString('Kurang Data Lambat', $text);
        $this->assertStringNotContainsString('ell-through', json_encode($book->getSheetByName('Penjelasan')->toArray()), 'Istilah lama tidak tersisa.');
        $book->disconnectWorksheets();
    }

    public function test_pdf_card_has_no_dangling_separators_zero_receivable_text_and_whole_numbers(): void
    {
        $row = $this->row(['owner_name' => '', 'phone' => '', 'address' => '', 'maps_url' => null, 'receivable' => 0,
            'pending_packs' => 5.0, 'pending_age_days' => 7.0, 'default_qty_mika' => 5, 'price_per_pack' => 12000, 'units_per_pack' => 15]);
        $html = view('exports.kiosk-insights-pdf', [
            'data' => $this->data([$row]), 'summary' => KioskInsightsSummary::summarize($this->data([$row])),
            'meta' => KioskInsightsSummary::meta($this->data([$row])), 'includeSummary' => false, 'catalogPages' => [[$row]],
            'logo' => '', 'fonts' => ['fraunces_bold' => '', 'lato' => '', 'lato_bold' => ''],
        ])->render();
        $this->assertDoesNotMatchRegularExpression('/>\s*·\s*</', $html, 'Tidak ada pemisah menggantung.');
        $this->assertStringContainsString('Tidak ada', $html);
        $this->assertStringContainsString('5 mika · 7 hari', $html);
        $this->assertStringContainsString('jatah 5 mika', $html);
        $this->assertStringContainsString('Rp 800/biji', $html);
        $this->assertStringNotContainsString('5,0 mika', $html);
        $this->assertStringNotContainsString('Rp 0 ·', $html);
        // Mode satu owner: judul area tanpa nama usaha.
        $this->assertStringContainsString('<h2 style="margin-top: 2pt">Utara</h2>', $html);
    }

    public function test_pdf_summary_page_contains_reading_guide_and_no_separate_reading_page(): void
    {
        $captured = [];
        View::composer('exports.kiosk-insights-pdf', function ($view) use (&$captured): void {
            $captured = $view->getData();
        });
        $data = $this->data([$this->row()]);
        $data['summary_only'] = true;
        $pdf = KioskInsightsPdf::renderPart($data, true);
        $html = view('exports.kiosk-insights-pdf', $captured)->render();
        $this->assertStringContainsString('Cara membaca.', $html);
        $this->assertStringNotContainsString('<h2>Cara membaca laporan</h2>', $html);
        $this->assertStringContainsString('10 kedai paling laris', $html);
        $this->assertStringContainsString('% Laku', $html);
        $this->assertStringNotContainsString('ell-through', $html);
        $this->assertSame(1, $pdf->getDomPDF()->getCanvas()->get_page_count(), 'Ringkasan + cara membaca muat satu halaman untuk data kecil.');
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
        // Foto = kolom A; sel foto kosong bila ada thumbnail, atau berisi alasan (tanpa kolom Status Foto terpisah).
        $this->assertSame('Foto', $sheet->getCell('A6')->getValue());
        $this->assertSame('Foto gagal dimuat', $sheet->getCell('A9')->getValue());
        $this->assertSame(45.0, $sheet->getRowDimension(8)->getRowHeight());
        $this->assertSame('C7', $sheet->getFreezePane(), 'Kolom Foto + Nama Kedai beku, data mulai baris 7.');
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
