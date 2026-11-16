<?php

namespace App\Support;

use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class KioskInsightsPdf
{
    public static function filename(array $data, string $extension = 'pdf'): string
    {
        $business = ($data['all_owners'] ?? false) ? 'SemuaOwner' : ($data['business_name'] ?? 'Usaha');
        $business = preg_replace('/[^A-Za-z0-9]/', '', Str::ascii($business)) ?: 'Usaha';
        $extension = in_array(strtolower($extension), ['pdf', 'xlsx'], true) ? strtolower($extension) : 'pdf';
        $date = Carbon::parse($data['as_of'] ?? now()->toDateString());
        $months = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

        return 'Laporan Kedai '.substr($business, 0, 100).' '.$date->day.' '.$months[$date->month].' '.$date->year.'.'.$extension;
    }

    /** Full sample report; production should use bounded parts and merge them. */
    public static function render(array $data): PdfDocument
    {
        return self::build($data, true, true);
    }

    /** The caller owns batching and final merged page numbering. */
    public static function renderPart(array $data, bool $includeSummary = false, bool $numberPages = false): PdfDocument
    {
        if (! ($data['summary_only'] ?? false) && count($data['rows'] ?? []) > 18) {
            throw new \InvalidArgumentException('Render PDF per bagian dibatasi maksimal 18 kedai.');
        }

        return self::build($data, $includeSummary, $numberPages);
    }

    private static function build(array $data, bool $includeSummary, bool $numberPages): PdfDocument
    {
        File::ensureDirectoryExists(storage_path('fonts'));
        $pages = [];
        $group = [];
        $previous = null;
        foreach (($data['summary_only'] ?? false) ? [] : ($data['rows'] ?? []) as $row) {
            $key = json_encode([$row['owner_id'] ?? null, $row['area'] ?? '']);
            if ($group && ($key !== $previous || count($group) === 6)) {
                $pages[] = $group;
                $group = [];
            }
            $group[] = $row;
            $previous = $key;
        }
        if ($group) {
            $pages[] = $group;
        }
        $fonts = str_replace('\\', '/', resource_path('fonts/invoice'));
        $view = [
            'data' => $data,
            // A caller can pass the full report summary when rendering only the first row batch.
            'summary' => $data['summary'] ?? KioskInsightsSummary::summarize($data),
            'meta' => KioskInsightsSummary::meta($data),
            'includeSummary' => $includeSummary,
            'catalogPages' => $pages,
            'logo' => public_path('images/brand/cq-logo-utama.png'),
            'fonts' => ['fraunces_bold' => $fonts.'/Fraunces72pt-SemiBold.ttf', 'lato' => $fonts.'/Lato-Regular.ttf', 'lato_bold' => $fonts.'/Lato-Bold.ttf'],
        ];
        $pdf = Pdf::loadView('exports.kiosk-insights-pdf', $view)->setPaper('a4', 'portrait')
            ->setOption('isRemoteEnabled', false)->setOption('isPhpEnabled', false)
            ->setOption('isFontSubsettingEnabled', true);
        $pdf->render();
        if ($numberPages) {
            $dompdf = $pdf->getDomPDF();
            $canvas = $dompdf->getCanvas();
            $metrics = $dompdf->getFontMetrics();
            $font = $metrics->getFont('Lato', 'normal') ?: $metrics->getFont('DejaVu Sans', 'normal');
            $count = $canvas->get_page_count();
            $width = $metrics->getTextWidth("Hal {$count}/{$count}", $font, 8);
            $canvas->page_text($canvas->get_width() - 36 - $width, $canvas->get_height() - 25,
                'Hal {PAGE_NUM}/{PAGE_COUNT}', $font, 8, [0.45, 0.42, 0.40]);
        }

        return $pdf;
    }
}
