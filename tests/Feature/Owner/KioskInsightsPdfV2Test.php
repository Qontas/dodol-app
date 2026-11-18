<?php

namespace Tests\Feature\Owner;

use App\Support\KioskInsightExport;
use App\Support\KioskInsightsPdf;
use App\Support\KioskInsightsSummary;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Tests\TestCase;

class KioskInsightsPdfV2Test extends TestCase
{
    public static function sampleData(): array
    {
        $rows = [];
        for ($i = 1; $i <= 8; $i++) {
            $rows[] = [
                'id' => $i, 'owner_id' => 1, 'business_name' => 'Usaha Contoh', 'area' => 'Medan',
                'name' => 'Kedai Contoh Nama Panjang Lebih Dari Empat Puluh Karakter Nomor '.$i,
                'owner_name' => 'Pemilik Contoh', 'phone' => $i === 1 ? '0800000000' : '',
                'address' => '', 'maps_address' => $i === 1 ? 'Jalan Contoh 12, Kelurahan Contoh, Kecamatan Contoh, Medan' : '',
                'latitude' => 3.5952, 'longitude' => 98.6722, 'maps_url' => null,
                'type' => 'Konsinyasi', 'is_active' => true, 'label_key' => 'problem', 'display_label_key' => 'debt', 'label' => 'Piutang lama',
                'settled_count' => 3, 'sold_out_count' => 2, 'weekly_sales' => 59, 'units_per_pack' => 20,
                'sell_through_pct' => 95, 'bs_pct' => 17, 'fresh_return_pct' => 22,
                'price_per_pack' => 16000, 'default_qty_mika' => 3, 'pending_packs' => 2, 'pending_age_days' => 1,
                'receivable' => 32000, 'receivable_age_days' => 31, 'revenue_30' => 180000, 'revenue_total' => 180000,
                'visit_count' => 5, 'visits_30' => 4, 'pending_value' => 32000,
                'last_visit_at' => '2026-11-17 09:00:00', 'last_operator' => 'Operator Contoh',
                'photo_status' => 'Tidak ada foto', 'priority' => 1,
                'suggestions' => [
                    'Ada piutang Rp 32.000 sejak 31 hari. Tagih di kunjungan berikutnya.',
                    'Banyak dodol rusak/kedaluwarsa (17%). Cek tempat penyimpanan (panas/lembap) atau dodol terlalu lama di kedai.',
                ],
            ];
        }

        return ['generated_at' => '2026-11-18T05:00:00Z', 'as_of' => '2026-11-18', 'period_start' => null,
            'business_name' => 'Usaha Contoh', 'all_owners' => false, 'rules' => config('kiosk_insights'), 'rows' => $rows];
    }

    public function test_card_text_is_plain_complete_and_handles_missing_address_phone_and_data(): void
    {
        $data = self::sampleData();
        $data['rows'][1] = array_replace($data['rows'][1], ['settled_count' => 1, 'label_key' => 'insufficient', 'weekly_sales' => null]);
        $captured = [];
        View::composer('exports.kiosk-insights-pdf', function ($view) use (&$captured): void {
            $captured = $view->getData();
        });
        $pdf = KioskInsightsPdf::renderPart($data);
        $html = view('exports.kiosk-insights-pdf', $captured)->render();
        foreach (['Laku ±59 biji/minggu (±3 mika)', 'Terjual 95% dari yang dititip',
            'Rusak/kedaluwarsa 17% · Belum laku 22%', 'Sekarang di kedai: 2 mika (dititip 1 hari lalu)',
            'Omset 30 hari:', 'HP pemilik: 0800000000', 'Alamat belum diisi', '— (baru ditagih 1 kali)',
            'google.com/maps?q=3.5952,98.6722', 'Jalan Contoh 12'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertSame(1, substr_count($html, 'HP pemilik:'));
        $this->assertStringNotContainsString('height: 195pt', $html);
        $this->assertStringContainsString('page-break-inside: avoid', $html);
        $this->assertStringContainsString('/Subtype /Link', $pdf->output());
        $this->assertStringContainsString('/URI', $pdf->output());
    }

    public function test_long_names_and_multiline_suggestions_remain_within_card_borders_on_one_page(): void
    {
        $data = self::sampleData();
        $fonts = str_replace('\\', '/', resource_path('fonts/invoice'));
        $pdf = Pdf::loadView('exports.kiosk-insights-pdf', [
            'data' => $data, 'summary' => KioskInsightsSummary::summarize($data), 'meta' => KioskInsightsSummary::meta($data),
            'includeSummary' => false, 'catalogPages' => array_chunk($data['rows'], 4), 'logo' => '',
            'fonts' => ['fraunces_bold' => $fonts.'/Fraunces72pt-SemiBold.ttf', 'lato' => $fonts.'/Lato-Regular.ttf', 'lato_bold' => $fonts.'/Lato-Bold.ttf'],
        ])->setPaper('a4')->setOption('isRemoteEnabled', false);
        $cards = $suggestions = [];
        $dompdf = $pdf->getDomPDF();
        $dompdf->setCallbacks([['event' => 'end_frame', 'f' => function ($frame, $canvas) use (&$cards, &$suggestions): void {
            $node = $frame->get_node();
            if (! $node instanceof \DOMElement) {
                return;
            }
            $class = $node->getAttribute('class');
            if (! in_array($class, ['card', 'suggest'], true)) {
                return;
            }
            $position = $frame->get_position();
            $box = ['page' => $canvas->get_page_number(), 'top' => $position['y'],
                'bottom' => $position['y'] + $frame->get_margin_height()];
            if ($class === 'card') {
                $cards[] = $box;
            } else {
                $suggestions[] = $box;
            }
        }]]);
        $pdf->render();
        $this->assertCount(8, $cards, 'No card is split or lost.');
        $this->assertCount(8, $suggestions);
        foreach ($cards as $i => $card) {
            $this->assertSame($card['page'], $suggestions[$i]['page']);
            $this->assertGreaterThanOrEqual($card['top'], $suggestions[$i]['top']);
            $this->assertLessThanOrEqual($card['bottom'], $suggestions[$i]['bottom'], 'Suggestion stays inside card border.');
            $this->assertLessThan(800, $card['bottom'], 'Card stays above page footer.');
        }
        $this->assertSame(2, $dompdf->getCanvas()->get_page_count(), 'Four detailed cards per A4 page.');
    }

    public function test_production_merge_preserves_absolute_external_link_annotations(): void
    {
        $id = (string) Str::uuid();
        $directory = storage_path('app/private/kiosk-insight-exports/'.$id);
        File::ensureDirectoryExists($directory);
        try {
            $data = self::sampleData();
            $data['rows'] = array_slice($data['rows'], 0, 2);
            KioskInsightsPdf::renderPart($data)->save($directory.'/part-0.pdf');
            (new \ReflectionMethod(KioskInsightExport::class, 'mergePdf'))->invoke(null, $id, 1);
            $final = File::get($directory.'/report.pdf');
            $this->assertStringContainsString('/Subtype /Link', $final);
            $this->assertStringContainsString('/URI', $final);
            $this->assertStringContainsString('https://google.com/maps?q=3.5952,98.6722', $final);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_weekly_mika_rounding_and_low_volume_suppression(): void
    {
        $row = self::sampleData()['rows'][0];
        $this->assertSame('Laku ±59 biji/minggu (±3 mika)', KioskInsightsSummary::weeklySalesText($row));
        $this->assertSame('Laku ±9 biji/minggu', KioskInsightsSummary::weeklySalesText(array_replace($row, ['weekly_sales' => 9])));
        $this->assertSame('Laku ±10 biji/minggu (±0,5 mika)', KioskInsightsSummary::weeklySalesText(array_replace($row, ['weekly_sales' => 10])));
    }
}
