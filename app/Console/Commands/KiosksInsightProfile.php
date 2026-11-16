<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\KioskInsights;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class KiosksInsightProfile extends Command
{
    protected $signature = 'kiosks:insight-profile {--owner= : ID owner wajib, tidak ada default lintas usaha} {--as-of= : Tanggal acuan YYYY-MM-DD}';

    protected $description = 'Profil read-only metrik rapor kedai: waktu, jumlah query, memori, dan jumlah baris';

    public function handle(): int
    {
        $owner = (string) ($this->option('owner') ?? '');
        if (! ctype_digit($owner) || (int) $owner < 1 || ! User::query()->whereKey((int) $owner)->where('role', 'owner')->exists()) {
            $this->error('--owner wajib berupa ID owner yang valid.');

            return self::FAILURE;
        }
        $input = $this->option('as-of');
        if ($input !== null && (! is_string($input) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $input) || ! checkdate((int) substr($input, 5, 2), (int) substr($input, 8, 2), (int) substr($input, 0, 4)))) {
            $this->error('--as-of harus tanggal valid YYYY-MM-DD.');

            return self::FAILURE;
        }
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });
        $start = hrtime(true);
        $memory = memory_get_usage(true);
        $data = KioskInsights::build((int) $owner, $input ? Carbon::parse($input) : null);
        $rows = collect($data['rows']);
        $distribution = static function (array $values): array {
            sort($values, SORT_NUMERIC);
            $n = count($values);
            $percentiles = [];
            foreach ([0, 25, 50, 75, 90, 100] as $p) {
                $percentiles['p'.$p] = $n ? $values[max(0, (int) ceil($p / 100 * $n) - 1)] : null;
            }

            return ['count' => $n, 'percentiles' => $percentiles];
        };
        $this->line(json_encode([
            'owner_id' => (int) $owner,
            'as_of' => $data['as_of'],
            'rows' => count($data['rows']),
            'queries' => $count,
            'elapsed_ms' => round((hrtime(true) - $start) / 1e6, 2),
            'memory_delta_bytes' => memory_get_usage(true) - $memory,
            'peak_memory_bytes' => memory_get_peak_usage(true),
            'settlements_per_kiosk' => $distribution($rows->pluck('settled_count')->all()),
            'sell_through_pct' => $distribution($rows->pluck('sell_through_pct')->filter(fn ($v) => $v !== null)->all()),
            'visit_gap_days' => $distribution($rows->pluck('avg_visit_gap_days')->filter(fn ($v) => $v !== null)->all()),
            'weekly_sales_units' => $distribution($rows->pluck('weekly_sales')->filter(fn ($v) => $v !== null)->all()),
            'owner_relative_rate_percentile' => $distribution($rows->pluck('rate_percentile')->filter(fn ($v) => $v !== null)->all()),
            'settlement_count_histogram' => $rows->countBy('settled_count')->sortKeys()->all(),
            'labels' => $rows->countBy('label')->all(),
            'thresholds' => $data['rules'],
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
