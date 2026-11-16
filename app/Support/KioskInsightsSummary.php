<?php

namespace App\Support;

/** Presentation-only totals shared by the workbook and the PDF; never queries the database. */
class KioskInsightsSummary
{
    public static function summarize(array $data): array
    {
        $rows = $data['rows'] ?? [];
        $labels = array_fill_keys(['insufficient', 'fast', 'stable', 'slow', 'problem', 'inactive'], 0);
        $areas = [];
        $totals = ['kiosks' => count($rows), 'active' => 0, 'visit_count' => 0, 'visits_30' => 0,
            'revenue_total' => 0, 'revenue_30' => 0, 'pending_packs' => 0, 'pending_value' => 0, 'receivable' => 0];

        foreach ($rows as $row) {
            $label = $row['label_key'] ?? 'insufficient';
            $labels[array_key_exists($label, $labels) ? $label : 'insufficient']++;
            $totals['active'] += (int) ($row['is_active'] ?? false);
            foreach (['visit_count', 'visits_30', 'revenue_total', 'revenue_30', 'pending_packs', 'pending_value', 'receivable'] as $key) {
                $totals[$key] += (float) ($row[$key] ?? 0);
            }
            // An area with the same name in two businesses is still two separate groups.
            $key = json_encode([$row['owner_id'] ?? null, $row['area'] ?? '']);
            $areas[$key] ??= ['owner_id' => $row['owner_id'] ?? null, 'business_name' => $row['business_name'] ?? '',
                'area' => ($row['area'] ?? '') ?: 'Tanpa area', 'kiosks' => 0, 'active' => 0, 'revenue_30' => 0, 'pending_value' => 0, 'receivable' => 0];
            $areas[$key]['kiosks']++;
            $areas[$key]['active'] += (int) ($row['is_active'] ?? false);
            foreach (['revenue_30', 'pending_value', 'receivable'] as $field) {
                $areas[$key][$field] += (float) ($row[$field] ?? 0);
            }
        }

        $minimum = max(1, (int) ($data['rules']['min_settlements'] ?? 3));
        $eligible = array_values(array_filter($rows, fn (array $r): bool => ($r['type'] ?? '') === 'Konsinyasi'
            && ($r['settled_count'] ?? 0) >= $minimum && ($r['weekly_sales'] ?? null) !== null));
        $sort = static function (array $a, array $b): int {
            return ((float) $b['weekly_sales'] <=> (float) $a['weekly_sales'])
                ?: strnatcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''))
                ?: (($a['id'] ?? 0) <=> ($b['id'] ?? 0));
        };
        usort($eligible, $sort);
        $slow = $eligible;
        usort($slow, fn (array $a, array $b): int => ((float) $a['weekly_sales'] <=> (float) $b['weekly_sales'])
            ?: strnatcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''))
            ?: (($a['id'] ?? 0) <=> ($b['id'] ?? 0)));

        $percentages = array_column(array_filter($rows, fn (array $r): bool => ($r['type'] ?? '') === 'Konsinyasi'
            && isset($r['sell_through_pct'])), 'sell_through_pct');

        return ['totals' => $totals, 'labels' => $labels, 'areas' => array_values($areas),
            'avg_sell_through_pct' => $percentages ? array_sum($percentages) / count($percentages) : null,
            'top10' => array_slice($eligible, 0, 10), 'slow10' => array_slice($slow, 0, 10), 'eligible_count' => count($eligible)];
    }

    /** HTTPS maps links only; never execute spreadsheet formulas or arbitrary URL schemes. */
    public static function mapsUrl(?string $url): ?string
    {
        if (! $url || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        $mapsHost = in_array($host, ['maps.google.com', 'maps.app.goo.gl', 'goo.gl'], true)
            || (in_array($host, ['google.com', 'www.google.com', 'google.co.id', 'www.google.co.id'], true)
                && str_starts_with($parts['path'] ?? '', '/maps'));

        return ($parts['scheme'] ?? '') === 'https' && $mapsHost && ! isset($parts['user']) && ! isset($parts['pass']) ? $url : null;
    }

    /** Accept only a decoded, local JPEG thumbnail; no original images or remote requests. */
    public static function thumbnail(array $row): ?string
    {
        $path = $row['thumbnail_path'] ?? null;
        if (($row['photo_status'] ?? '') !== 'ok' || ! is_string($path)
            || str_starts_with($path, '//') || str_starts_with($path, '\\\\')
            || ! preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $path) || ! is_file($path)) {
            return null;
        }
        $size = @getimagesize($path);

        return $size && $size[2] === IMAGETYPE_JPEG ? $path : null;
    }

    public static function photoStatus(array $row): string
    {
        if (self::thumbnail($row)) {
            return 'ok';
        }

        return ($row['photo_status'] ?? 'Tidak ada foto') === 'Tidak ada foto' ? 'Tidak ada foto' : 'Foto gagal dimuat';
    }

    /** Strip pictographs from every user-supplied string before PDF rendering (fonts have no emoji). */
    public static function pdfText(mixed $text): string
    {
        return preg_replace('/[\x{1F000}-\x{1FAFF}\x{2300}-\x{23FF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0E}\x{FE0F}\x{200D}]/u', '', (string) $text) ?? '';
    }
}
