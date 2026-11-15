<?php

namespace Tests\Unit;

use App\Support\AppFeeInvoicePdf;
use App\Support\IdDate;
use Barryvdh\DomPDF\Facade\Pdf;
use Dompdf\Canvas;
use Dompdf\Frame;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Poles tampilan PDF invoice (brief 15 Nov 2026, feedback PDF prod). Murni tampilan:
 * tidak menyentuh DB, angka, atau aturan bisnis — data dirakit in-memory.
 */
class AppFeeInvoicePdfLayoutTest extends TestCase
{
    /** @return array<string, mixed> */
    private function data(int $rows, string $mode = 'issued', array $override = []): array
    {
        $lines = [];
        for ($i = 0; $i < $rows; $i++) {
            $day = $i % 28 + 1;
            $mika = 80 + ($i * 7) % 90;
            $lines[] = ['date' => sprintf('2026-10-%02d', $day), 'mika' => $mika, 'fee' => $mika * 300.0, 'min_rate' => 300.0, 'max_rate' => 300.0];
        }
        usort($lines, fn ($a, $b) => strcmp($a['date'], $b['date']));

        $base = [
            'mode' => $mode, 'number' => $mode === 'draft' ? null : 'CQ-INV-202610-001', 'period' => '2026-10',
            'issued_at' => $mode === 'draft' ? null : Carbon::parse('2026-11-03 10:00'),
            'due_date' => $mode === 'draft' ? null : Carbon::parse('2026-11-10'),
            'paid_at' => $mode === 'paid' ? Carbon::parse('2026-11-08') : null, 'paid_note' => $mode === 'paid' ? 'Transfer Bank Contoh' : null,
            'voided_at' => $mode === 'void' ? Carbon::parse('2026-11-05 09:00') : null,
            'void_reason' => $mode === 'void' ? 'Contoh pembatalan.' : null,
            'issuer' => [
                'issuer_name' => 'Cemilan Qontas', 'address' => "Jl. Contoh No. 12\nKota Contoh", 'phone' => '0812-3456-7890',
                'email' => 'tagihan@contoh.id', 'bank_name' => 'Bank Contoh', 'account_number' => '1234 5678 90',
                'account_holder' => 'Nama Pemilik Contoh', 'due_days' => 7, 'footer_note' => 'Mohon cantumkan nomor invoice pada berita transfer.',
            ],
            'billed_to' => ['business' => 'Kuali Stories', 'owner_name' => 'Contoh Owner'],
            'lines' => $lines,
            'total_mika' => array_sum(array_column($lines, 'mika')),
            'total_fee' => array_sum(array_column($lines, 'fee')),
        ];

        $normalize = new ReflectionMethod(AppFeeInvoicePdf::class, 'normalize');

        return $normalize->invoke(null, array_merge($base, $override));
    }

    private function pages(array $data): int
    {
        return AppFeeInvoicePdf::pageCount(AppFeeInvoicePdf::render($data));
    }

    // ---- format tanggal + nama hari ----------------------------------------------------

    public function test_short_date_with_indonesian_weekday(): void
    {
        // 2026-10-20 = Selasa; 2026-10-18 = Minggu; 2026-10-24 = Sabtu
        $this->assertSame('20 Okt · Sel', IdDate::tanggalHari(Carbon::parse('2026-10-20')));
        $this->assertSame('18 Okt · Min', IdDate::tanggalHari(Carbon::parse('2026-10-18')));
        $this->assertSame('24 Okt · Sab', IdDate::tanggalHari(Carbon::parse('2026-10-24')));
    }

    public function test_all_seven_weekday_and_twelve_month_abbreviations(): void
    {
        $weekdays = [];
        foreach (range(11, 17) as $d) { // 2026-05-11 = Senin
            $weekdays[] = IdDate::hariSingkat(Carbon::parse(sprintf('2026-05-%02d', $d)));
        }
        $this->assertSame(['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'], $weekdays);

        $months = [];
        foreach (range(1, 12) as $m) {
            $months[] = explode(' ', IdDate::tanggalHari(Carbon::create(2026, $m, 5)))[1];
        }
        $this->assertSame(['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'], $months);
    }

    // ---- baris "u.p." -------------------------------------------------------------------

    public function test_attention_line_hidden_when_equal_after_normalizing_spaces_and_case(): void
    {
        $this->assertFalse(AppFeeInvoicePdf::showAttention(['business' => 'Kuali Stories', 'owner_name' => 'kualistories']));
        $this->assertFalse(AppFeeInvoicePdf::showAttention(['business' => 'KUALI  STORIES', 'owner_name' => ' Kuali Stories ']));
        $this->assertFalse(AppFeeInvoicePdf::showAttention(['business' => 'Kuali Stories', 'owner_name' => '']));
        $this->assertTrue(AppFeeInvoicePdf::showAttention(['business' => 'Kuali Stories', 'owner_name' => 'Rian Saputra']));
    }

    public function test_attention_line_rendered_only_when_different(): void
    {
        $same = $this->data(3, override: ['billed_to' => ['business' => 'Kuali Stories', 'owner_name' => 'kuali stories']]);
        $diff = $this->data(3);
        $this->assertFalse($same['show_attention']);
        $this->assertTrue($diff['show_attention']);
        $this->assertStringNotContainsString('u.p.', view('exports.app-fee-invoice-pdf', $same)->render());
        $this->assertStringContainsString('u.p. Contoh Owner', view('exports.app-fee-invoice-pdf', $diff)->render());
    }

    public function test_table_uses_short_date_not_full_month_year(): void
    {
        $html = view('exports.app-fee-invoice-pdf', $this->data(3))->render();
        $this->assertMatchesRegularExpression('/\d{1,2} (Okt) · (Min|Sen|Sel|Rab|Kam|Jum|Sab)/u', $html);
        $this->assertStringNotContainsString('Oktober 2026 </td>', $html);
    }

    // ---- jumlah halaman ----------------------------------------------------------------

    public function test_eleven_rows_fit_one_page_for_every_status(): void
    {
        foreach (['draft', 'issued', 'paid', 'void'] as $mode) {
            $this->assertSame(1, $this->pages($this->data(11, $mode)), "11 baris ({$mode}) harus 1 halaman");
        }
    }

    public function test_twenty_rows_fit_one_full_page_with_totals_and_payment(): void
    {
        foreach (['draft', 'issued', 'paid', 'void'] as $mode) {
            $this->assertSame(1, $this->pages($this->data(20, $mode)), "20 baris ({$mode}) harus 1 halaman penuh");
        }
    }

    public function test_thirty_one_rows_split_into_two_pages_with_intact_summary_block(): void
    {
        $data = $this->data(31);
        [$pdf, $text] = $this->renderWithPageTexts($data);
        $this->assertSame(2, $pdf->getDomPDF()->getCanvas()->get_page_count());

        // Blok total + terbilang + pembayaran satu kesatuan: seluruh teksnya di halaman TERAKHIR.
        $this->assertCount(2, $text);
        $terbilang = $data['terbilang'];
        foreach (['Total tagihan', 'Pembayaran ke', 'Rp '.number_format($data['total_fee'], 0, ',', '.'), $terbilang, 'Bank Contoh', '1234 5678 90'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $text[0], "'{$needle}' tidak boleh ada di hal. 1");
            $this->assertStringContainsStringIgnoringCase($needle, $text[1], "'{$needle}' harus utuh di hal. 2");
        }
        // Header tabel berulang di halaman 2.
        $this->assertStringContainsStringIgnoringCase('Mika kena fee', $text[1]);
    }

    public function test_pdf_stays_small_with_embedded_subset_fonts(): void
    {
        $bytes = strlen(AppFeeInvoicePdf::render($this->data(31, 'draft'))->output());
        $this->assertLessThan(150 * 1024, $bytes, "PDF {$bytes} byte melebihi 150 KB — subsetting font tidak aktif?");
    }

    /**
     * Render + teks tiap halaman. Font subset menulis glyph-id (bukan huruf) di stream PDF dan
     * pohon frame dompdf sudah kosong setelah render, jadi teks ditangkap lewat callback
     * `begin_frame` saat frame dicat, dicatat per nomor halaman canvas.
     *
     * @return array{0: \Barryvdh\DomPDF\PDF, 1: array<int, string>}
     */
    private function renderWithPageTexts(array $data): array
    {
        File::ensureDirectoryExists(storage_path('fonts'));
        $texts = [];
        $pdf = Pdf::loadView('exports.app-fee-invoice-pdf', $data)
            ->setPaper('a4', 'portrait')
            ->setOption('isFontSubsettingEnabled', true);
        $pdf->getDomPDF()->setCallbacks([[
            'event' => 'begin_frame',
            'f' => function (Frame $frame, Canvas $canvas) use (&$texts): void {
                if ($frame->is_text_node()) {
                    $n = $canvas->get_page_number();
                    $texts[$n] = ($texts[$n] ?? '').' '.$frame->get_node()->nodeValue;
                }
            },
        ]]);
        $pdf->render();
        ksort($texts);

        return [$pdf, array_values($texts)];
    }
}
