<?php

namespace App\Support;

use App\Models\AppFeeInvoice;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response;

/**
 * Render invoice fee aplikasi ke PDF (dompdf — engine yang sama dgn export Laporan
 * Periode/Trip; karena itu logo PAKAI PNG, dompdf lemah di SVG).
 *
 * Dua sumber data, SATU view (exports.app-fee-invoice-pdf):
 *  - invoice TERBIT/void/lunas → dibaca dari snapshot beku di DB (tak berubah);
 *  - DRAFT → dihitung LIVE lewat AppFeeInvoiceService::compute (tanpa nomor, tak disimpan).
 */
class AppFeeInvoicePdf
{
    /** @return array<string, mixed> */
    public static function dataFromInvoice(AppFeeInvoice $invoice): array
    {
        return self::normalize([
            'mode' => $invoice->status, // issued | paid | void
            'number' => $invoice->number,
            'period' => $invoice->period,
            'issued_at' => $invoice->issued_at,
            'due_date' => $invoice->due_date,
            'paid_at' => $invoice->paid_at,
            'paid_note' => $invoice->paid_note,
            'voided_at' => $invoice->voided_at,
            'void_reason' => $invoice->void_reason,
            'issuer' => $invoice->issuer,
            'billed_to' => $invoice->billed_to,
            'lines' => $invoice->lines,
            'total_mika' => $invoice->total_mika,
            'total_fee' => $invoice->total_fee,
        ]);
    }

    /** @return array<string, mixed> */
    public static function dataForDraft(User $owner, string $period): array
    {
        $calc = AppFeeInvoiceService::compute($owner, $period);

        return self::normalize([
            'mode' => 'draft',
            'number' => null,
            'period' => $period,
            'issued_at' => null,
            'due_date' => null,
            'paid_at' => null,
            'paid_note' => null,
            'voided_at' => null,
            'void_reason' => null,
            'issuer' => InvoiceSettings::get(),
            'billed_to' => ['business' => $owner->business_name ?: $owner->name, 'owner_name' => $owner->name],
            'lines' => $calc['lines'],
            'total_mika' => $calc['total_mika'],
            'total_fee' => $calc['total_fee'],
        ]);
    }

    /** @param  array<string, mixed>  $d */
    private static function normalize(array $d): array
    {
        [$y, $m] = array_map('intval', explode('-', $d['period']));
        $start = Carbon::create($y, $m, 1);
        $end = (clone $start)->endOfMonth();

        // Hanya tulis tarif seragam kalau tarif aktual MIN=MAX lintas semua hari.
        // Rata-rata harian yang sama tidak membuktikan tarif aslinya seragam.
        $lines = collect($d['lines']);
        $hasRates = $lines->isNotEmpty() && $lines->every(fn (array $l): bool => isset($l['min_rate'], $l['max_rate']));
        $d['uniform_rate'] = $hasRates && (float) $lines->min('min_rate') === (float) $lines->max('max_rate')
            ? (float) $lines->min('min_rate')
            : null;

        $d['period_label'] = IdDate::bulanTahun($d['period']);
        $d['period_range'] = '1–'.$end->day.' '.IdDate::bulan($m).' '.$y;
        $d['terbilang'] = Terbilang::rupiah($d['total_fee']);
        $d['issuer'] = array_merge(InvoiceSettings::defaults(), $d['issuer'] ?? []);
        $d['logo'] = public_path('images/brand/cq-logo-utama.png');
        $d['fonts'] = self::fontUrls();
        $d['show_attention'] = self::showAttention($d['billed_to'] ?? []);

        return $d;
    }

    /**
     * Baris "u.p. <nama>" disembunyikan bila nama user == nama usaha setelah dinormalisasi
     * (abaikan spasi & huruf besar/kecil) — menghindari baris dobel "Kuali Stories / u.p. Kuali Stories".
     *
     * @param  array<string, mixed>  $billedTo
     */
    public static function showAttention(array $billedTo): bool
    {
        $norm = fn (?string $s): string => mb_strtolower((string) preg_replace('/\s+/u', '', (string) $s));
        $owner = $norm($billedTo['owner_name'] ?? '');

        return $owner !== '' && $owner !== $norm($billedTo['business'] ?? '');
    }

    /**
     * Font OFL (Fraunces = judul/angka total/nama penerbit; Lato = isi) disimpan di
     * resources/fonts/invoice dan didaftarkan lewat @font-face dompdf (subsetting aktif).
     *
     * @return array<string, string>
     */
    private static function fontUrls(): array
    {
        $dir = str_replace('\\', '/', resource_path('fonts/invoice'));

        return [
            'fraunces_bold' => $dir.'/Fraunces72pt-SemiBold.ttf',
            'lato' => $dir.'/Lato-Regular.ttf',
            'lato_bold' => $dir.'/Lato-Bold.ttf',
            'lato_italic' => $dir.'/Lato-Italic.ttf',
        ];
    }

    /** Lebar penuh teks "Hal n/n" supaya ujung kanannya rata dgn margin kanan konten. */
    private const PAGE_MARGIN_RIGHT = 44;

    public static function filename(array $d): string
    {
        // "KualiStories": hanya huruf/angka (spasi & simbol dibuang), aman di semua OS.
        $usaha = preg_replace('/[^A-Za-z0-9]/', '', (string) ($d['billed_to']['business'] ?? '')) ?: 'Owner';
        $nomor = $d['number'] ?? 'DRAFT';

        return "Invoice {$nomor} {$usaha} {$d['period_label']}.pdf";
    }

    /**
     * Render + gambar nomor halaman. Dipisah dari download() agar jumlah halaman bisa diuji.
     *
     * @param  array<string, mixed>  $d
     */
    public static function render(array $d): \Barryvdh\DomPDF\PDF
    {
        // dompdf menyalin font @font-face ke font_dir (storage/fonts) + membuat installed-fonts.json.
        File::ensureDirectoryExists(storage_path('fonts'));

        $pdf = Pdf::loadView('exports.app-fee-invoice-pdf', $d)
            ->setPaper('a4', 'portrait')
            ->setOption('isFontSubsettingEnabled', true);
        $pdf->render();

        // Nomor halaman "Hal 1/2" — dompdf tak punya counter CSS, digambar di canvas.
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $metrics = $dompdf->getFontMetrics();
        $font = $metrics->getFont('Lato', 'normal') ?: $metrics->getFont('DejaVu Sans', 'normal');
        $pages = $canvas->get_page_count();
        // Rata kanan ke margin kanan konten: ukur teks terpanjang ("Hal n/n").
        $textWidth = $metrics->getTextWidth("Hal {$pages}/{$pages}", $font, 8);
        $canvas->page_text(
            $canvas->get_width() - self::PAGE_MARGIN_RIGHT - $textWidth,
            $canvas->get_height() - 34,
            'Hal {PAGE_NUM}/{PAGE_COUNT}',
            $font,
            8,
            [0.45, 0.42, 0.40]
        );

        return $pdf;
    }

    /** Jumlah halaman hasil render (untuk test & laporan). */
    public static function pageCount(\Barryvdh\DomPDF\PDF $pdf): int
    {
        return $pdf->getDomPDF()->getCanvas()->get_page_count();
    }

    /** @param  array<string, mixed>  $d */
    public static function download(array $d): Response
    {
        return self::render($d)->download(self::filename($d));
    }
}
