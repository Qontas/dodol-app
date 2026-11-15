<?php

namespace App\Support;

use App\Models\AppFeeInvoice;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
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

        return $d;
    }

    public static function filename(array $d): string
    {
        // "KualiStories": hanya huruf/angka (spasi & simbol dibuang), aman di semua OS.
        $usaha = preg_replace('/[^A-Za-z0-9]/', '', (string) ($d['billed_to']['business'] ?? '')) ?: 'Owner';
        $nomor = $d['number'] ?? 'DRAFT';

        return "Invoice {$nomor} {$usaha} {$d['period_label']}.pdf";
    }

    /** @param  array<string, mixed>  $d */
    public static function download(array $d): Response
    {
        $pdf = Pdf::loadView('exports.app-fee-invoice-pdf', $d)
            ->setPaper('a4', 'portrait')
            ->setOption('isFontSubsettingEnabled', true);
        $pdf->render();

        // Nomor halaman "Hal 1/2" — dompdf tak punya counter CSS, digambar di canvas.
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
        $canvas->page_text(
            $canvas->get_width() - 44 - 60,
            $canvas->get_height() - 34,
            'Hal {PAGE_NUM}/{PAGE_COUNT}',
            $font,
            8,
            [0.45, 0.42, 0.40]
        );

        return $pdf->download(self::filename($d));
    }
}
