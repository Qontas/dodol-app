<?php

namespace Tests\Feature\Owner;

use App\Models\Cluster;
use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\KioskVisit;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Settlement;
use App\Models\Trip;
use App\Models\User;
use App\Support\KioskInsights;
use App\Support\OpeningBalance;
use App\Support\TripAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class KioskInsightsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['kiosk_insights.inactive_days' => 90]);
    }

    private function fixture(): array
    {
        $owner = User::factory()->create(['role' => 'owner', 'business_name' => 'Usaha metrik']);
        $operator = User::factory()->create(['role' => 'operator', 'owner_id' => $owner->id]);
        $cluster = Cluster::factory()->create(['owner_id' => $owner->id]);
        $product = Product::factory()->create(['owner_id' => $owner->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'is_active' => true, 'units_per_pack' => 15, 'sale_price_per_pack' => 12000]);
        $kiosk = Kiosk::factory()->create(['cluster_id' => $cluster->id, 'is_cash_only' => false, 'default_qty_mika' => 2, 'is_active' => true, 'first_titip_date' => '2026-10-01']);

        return compact('owner', 'operator', 'cluster', 'variant', 'kiosk');
    }

    private function settled(array $f, string $start, string $end, int $sold, array $deliveryOverrides = [], array $settlementOverrides = []): array
    {
        $source = Trip::factory()->create(['owner_id' => $f['owner']->id, 'operator_id' => $f['operator']->id, 'starting_cluster_id' => $f['cluster']->id, 'trip_date' => $start, 'ended_at' => $start.' 17:00:00']);
        $collector = Trip::factory()->create(['owner_id' => $f['owner']->id, 'operator_id' => $f['operator']->id, 'starting_cluster_id' => $f['cluster']->id, 'trip_date' => $end, 'trip_number_of_day' => $start === $end ? 2 : 1, 'ended_at' => $end.' 17:00:00']);
        $delivery = Delivery::factory()->create(array_merge(['kiosk_id' => $f['kiosk']->id, 'trip_id' => $source->id, 'product_variant_id' => $f['variant']->id, 'procurement_batch_id' => null, 'qty_delivered' => 2, 'units_per_pack_snapshot' => 15, 'created_at' => $start.' 08:00:00'], $deliveryOverrides));
        $settlement = Settlement::factory()->create(array_merge(['delivery_id' => $delivery->id, 'visit_date' => $end, 'qty_sold' => $sold, 'qty_returned_fresh' => $delivery->qty_biji_delivered - $sold, 'qty_returned_expired' => 0, 'amount_due' => $sold * 800, 'amount_paid' => $sold * 800], $settlementOverrides));
        $visit = KioskVisit::create(['trip_id' => $collector->id, 'kiosk_id' => $f['kiosk']->id, 'visit_action' => 'settle_only', 'settled_delivery_id' => $delivery->id, 'visited_at' => $end.' 09:00:00']);

        return compact('source', 'collector', 'delivery', 'settlement', 'visit');
    }

    private function row(array $f): array
    {
        return collect(KioskInsights::build($f['owner']->id, Carbon::parse('2026-11-16'))['rows'])->firstWhere('id', $f['kiosk']->id);
    }

    public function test_weighted_weekly_rate_counts_zero_sold_and_unpaid_stock_settlements(): void
    {
        $f = $this->fixture();
        $this->settled($f, '2026-10-01', '2026-10-08', 30, [], ['amount_paid' => 0]);
        $this->settled($f, '2026-10-09', '2026-10-23', 0);
        $this->settled($f, '2026-10-24', '2026-10-24', 15);
        $row = $this->row($f);
        $this->assertSame(3, $row['settled_count']);
        $this->assertSame(90, $row['settled_units']);
        $this->assertSame(50.0, $row['sell_through_pct']);
        $this->assertSame(round(7 * 45 / 22, 2), $row['weekly_sales']);
        $this->assertSame(50.0, $row['return_bs_pct']);
        $this->assertSame('2026-10-01', KioskInsights::build($f['owner']->id, Carbon::parse('2026-11-16'))['period_start']);
        $this->assertSame(24000.0, $row['receivable']);
        $this->assertSame(39, $row['receivable_age_days']);
        $this->assertSame('problem', $row['label_key']);
    }

    public function test_cash_and_recycled_bs_stay_in_totals_but_not_performance_and_source_archive_is_preserved(): void
    {
        $f = $this->fixture();
        $this->settled($f, '2026-10-01', '2026-10-08', 15);
        $cash = $this->settled($f, '2026-10-09', '2026-10-10', 30, ['delivery_type' => 'cash_sale']);
        $this->settled($f, '2026-10-11', '2026-10-12', 30, ['delivery_type' => 'bs_redistribution', 'cost_snapshot' => 0]);
        $cash['source']->delete();
        $row = $this->row($f);
        $this->assertSame(75, $row['sold_units']);
        $this->assertSame(30, $row['cash_sold_units']);
        $this->assertSame(60000.0, $row['revenue_total']);
        $this->assertSame(1, $row['settled_count']);
        $this->assertSame(15, $row['consignment_sold_units']);
        $this->assertSame(50.0, $row['sell_through_pct']);
        $this->assertSame('insufficient', $row['label_key']);
        $this->assertStringNotContainsString('uji tambah jatah', implode(' ', $row['suggestions']));
    }

    public function test_only_distinct_active_collected_deliveries_on_completed_real_unarchived_trips_count(): void
    {
        $f = $this->fixture();
        $first = $this->settled($f, '2026-10-01', '2026-10-08', 30);
        KioskVisit::create(['trip_id' => $first['collector']->id, 'kiosk_id' => $f['kiosk']->id, 'visit_action' => 'settle_only', 'settled_delivery_id' => $first['delivery']->id, 'visited_at' => '2026-10-08 10:00:00']);
        $corrected = $this->settled($f, '2026-10-09', '2026-10-10', 30);
        $corrected['visit']->update(['corrected_at' => now()]);
        $archived = $this->settled($f, '2026-10-11', '2026-10-12', 30);
        $archived['collector']->delete();
        $running = $this->settled($f, '2026-10-13', '2026-10-14', 30);
        $running['collector']->update(['ended_at' => null]);
        $row = $this->row($f);
        $this->assertSame(30, $row['sold_units']);
        $this->assertSame(24000.0, $row['revenue_total']);
        $this->assertSame(1, $row['settled_count']);
    }

    public function test_sentinel_stocks_are_included_but_not_visits_and_use_real_cycle_dates(): void
    {
        $f = $this->fixture();
        $this->travelTo(Carbon::parse('2026-10-01 08:00:00'));
        $delivery = OpeningBalance::create($f['kiosk'], 2, 'migration');
        $pending = $this->row($f);
        $this->assertSame(2, $pending['pending_packs']);
        $this->assertSame(46, $pending['pending_age_days']);
        $this->assertSame(0, $pending['visit_count']);
        $collector = Trip::factory()->create(['owner_id' => $f['owner']->id, 'operator_id' => $f['operator']->id, 'starting_cluster_id' => $f['cluster']->id, 'trip_date' => '2026-10-08', 'ended_at' => '2026-10-08 17:00:00']);
        Settlement::factory()->create(['delivery_id' => $delivery->id, 'visit_date' => '2026-10-08', 'qty_sold' => 30, 'qty_returned_fresh' => 0, 'qty_returned_expired' => 0, 'amount_due' => 24000, 'amount_paid' => 24000]);
        KioskVisit::create(['trip_id' => $collector->id, 'kiosk_id' => $f['kiosk']->id, 'visit_action' => 'settle_only', 'settled_delivery_id' => $delivery->id, 'visited_at' => '2026-10-08 09:00:00']);
        $this->assertSame(30.0, $this->row($f)['weekly_sales']);
        $this->assertSame(1, $this->row($f)['visit_count']);
        $ownerPlaced = Kiosk::factory()->create(['cluster_id' => $f['cluster']->id, 'default_qty_mika' => 2, 'first_titip_date' => null]);
        OpeningBalance::create($ownerPlaced, 2, 'owner_placed');
        $all = KioskInsights::build($f['owner']->id, Carbon::parse('2026-11-16'));
        $this->assertSame(46, collect($all['rows'])->firstWhere('id', $ownerPlaced->id)['pending_age_days']);
        $this->travelBack();
    }

    public function test_walk_in_hidden_and_tenant_access_refuses_other_owner_and_operator(): void
    {
        $a = $this->fixture();
        $b = $this->fixture();
        Kiosk::walkInSentinelFor($a['owner']->id);
        $this->actingAs($a['owner']);
        $this->assertCount(1, KioskInsights::build($a['owner']->id)['rows']);
        try {
            KioskInsights::build($b['owner']->id);
            $this->fail('Cross-owner read must fail.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->actingAs($a['operator']);
        $this->expectException(HttpException::class);
        KioskInsights::build($a['owner']->id);
    }

    public function test_superadmin_all_owner_percentiles_do_not_mix_businesses_and_labels_are_configurable(): void
    {
        $a = $this->fixture();
        $b = $this->fixture();
        foreach ([['2026-10-01', '2026-10-08'], ['2026-10-09', '2026-10-16'], ['2026-10-17', '2026-10-24']] as [$start, $end]) {
            $this->settled($a, $start, $end, 30);
            $this->settled($b, $start, $end, 30, ['qty_delivered' => 20], ['qty_returned_fresh' => 270]);
        }
        config(['kiosk_insights.labels.fast' => 'Cepat khusus', 'kiosk_insights.problem_bs_pct' => 101]);
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
        $rows = collect(KioskInsights::build(null, Carbon::parse('2026-11-16'))['rows']);
        $this->assertSame(100.0, $rows->firstWhere('id', $a['kiosk']->id)['rate_percentile']);
        $this->assertSame('Cepat khusus', $rows->firstWhere('id', $a['kiosk']->id)['label']);
        $this->assertSame('slow', $rows->firstWhere('id', $b['kiosk']->id)['label_key']);
    }

    public function test_bulk_query_count_constant_with_450_kiosks_and_builder_does_not_write(): void
    {
        $f = $this->fixture();
        $this->settled($f, '2026-10-01', '2026-10-08', 30);
        $this->actingAs($f['owner']);
        DB::enableQueryLog();
        $this->row($f);
        $small = count(DB::getQueryLog());
        DB::disableQueryLog();
        Kiosk::factory()->count(449)->create(['cluster_id' => $f['cluster']->id, 'default_qty_mika' => 2]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $data = KioskInsights::build($f['owner']->id, Carbon::parse('2026-11-16'));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(450, $data['rows']);
        $this->assertSame($small, count($queries));
        $this->assertLessThan(30, count($queries));
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/^select\b/i', $query['query']);
        }
    }

    public function test_fast_suggestions_use_current_pack_units_and_cap_increase_at_two(): void
    {
        $f = $this->fixture();
        foreach ([['2026-10-01', '2026-10-02'], ['2026-10-03', '2026-10-04'], ['2026-10-05', '2026-10-06']] as [$start, $end]) {
            $this->settled($f, $start, $end, 150, ['qty_delivered' => 10]);
        }
        $row = $this->row($f);
        $this->assertSame('fast', $row['label_key']);
        $this->assertStringContainsString('Naikkan jatah ke 4 mika', implode(' ', $row['suggestions']));
        $this->assertStringNotContainsString('Naikkan jatah ke 5', implode(' ', $row['suggestions']));
        config(['kiosk_insights.max_quota_increase' => 1]);
        $this->assertStringContainsString('Naikkan jatah ke 3 mika', implode(' ', $this->row($f)['suggestions']));
        $this->assertSame(2, $f['kiosk']->fresh()->default_qty_mika);
    }

    public function test_insufficient_precedes_problem_but_retains_debt_bs_revisit_suggestions_and_inactive_wins(): void
    {
        $f = $this->fixture();
        $this->settled($f, '2026-10-01', '2026-10-08', 0, [], ['qty_returned_fresh' => 0, 'qty_returned_expired' => 30, 'amount_due' => 10000, 'amount_paid' => 0]);
        $row = $this->row($f);
        $this->assertSame('insufficient', $row['label_key']);
        $text = implode(' ', $row['suggestions']);
        $this->assertStringContainsString('piutang', $text);
        $this->assertStringContainsString('Cek retur/BS (100,0%)', $text);
        $this->assertStringContainsString('Kunjungi — sudah 39 hari', $text);
        $this->assertStringNotContainsString('Turunkan jatah', $text);
        $this->assertStringNotContainsString('Naikkan jatah', $text);
        $this->assertSame(1, $row['priority']);
        config(['kiosk_insights.inactive_days' => 30]);
        $this->assertSame('inactive', $this->row($f)['label_key']);
        $f['kiosk']->update(['is_active' => false]);
        $this->assertSame('inactive', $this->row($f)['label_key']);
    }

    /**
     * Kunjungan (penagihan) pada tanggal-tanggal $visitDates. Trip sumber tiap kunjungan dibuat 1 hari sebelum
     * trip penagih supaya tidak bentrok dengan trip penagih lain (unik per owner+tanggal+nomor) — hanya tanggal
     * kunjungan yang memengaruhi jarak/hari-sejak-kunjungan; siklus 1 hari tidak mengganggu aturan yang diuji.
     */
    private function visitsOn(array $f, array $visitDates): void
    {
        foreach ($visitDates as $date) {
            $made = $this->settled($f, Carbon::parse($date)->subDay()->toDateString(), $date, 30);
            // Pengiriman nyata tertaut ke kunjungannya. Tanpa tautan ini metrik (sengaja) menghitungnya sebagai
            // "kunjungan tanpa kunjungan" di trip sumber, dan jarak antar kunjungan jadi palsu.
            $made['delivery']->update(['kiosk_visit_id' => $made['visit']->id]);
        }
    }

    /** 4 kunjungan tiap 7 hari berakhir pada $lastVisit → rerata jarak 7 hari, 3 jarak (cukup riwayat). */
    private function weeklyRhythm(array $f, string $lastVisit): void
    {
        $end = Carbon::parse($lastVisit);
        $this->visitsOn($f, array_map(fn (int $d): string => $end->copy()->subDays($d)->toDateString(), [21, 14, 7, 0]));
    }

    public function test_revisit_suggestion_is_relative_to_the_kiosks_own_rhythm(): void
    {
        $f = $this->fixture();
        $this->weeklyRhythm($f, '2026-11-05'); // asOf 2026-11-16 → 11 hari sejak kunjungan; biasanya tiap 7 hari
        $row = $this->row($f);
        $this->assertSame(11, $row['days_since_visit']);
        $this->assertSame(7.0, (float) $row['avg_visit_gap_days']);
        // ambang = max(10, 1,5 × 7 = 10,5) = 10,5 → 11 >= 10,5 → disarankan walau < ambang tetap 14 hari.
        $this->assertContains('Kunjungi — sudah 11 hari (biasanya tiap 7 hari).', $row['suggestions']);
        $this->assertSame(2, $row['priority']);
    }

    public function test_revisit_not_suggested_before_relative_threshold_and_min_floor_applies(): void
    {
        $f = $this->fixture();
        $this->weeklyRhythm($f, '2026-11-08'); // 8 hari sejak kunjungan; ambang max(10, 10,5) = 10,5
        $this->assertSame(8, $this->row($f)['days_since_visit']);
        $this->assertStringNotContainsString('Kunjungi', implode(' ', $this->row($f)['suggestions']));
        // Ambang minimal 10 hari menahan kedai ber-ritme sangat rapat: multiplier kecil tak boleh menurunkan di bawah 10.
        config(['kiosk_insights.revisit_gap_multiplier' => 0.5]);
        $this->assertStringNotContainsString('Kunjungi', implode(' ', $this->row($f)['suggestions']), '0,5×7=3,5 < lantai 10; 8 hari belum cukup.');
        config(['kiosk_insights.revisit_min_days' => 8]);
        $this->assertStringContainsString('Kunjungi — sudah 8 hari (biasanya tiap 7 hari).', implode(' ', $this->row($f)['suggestions']));
    }

    public function test_slow_rhythm_kiosk_is_not_nagged_at_the_fixed_threshold(): void
    {
        // Ritme tiap 21 hari: kunjungan 18 Agu, 8 Sep, 29 Sep, 27 Okt → 4 kunjungan, jarak 21/21/28 (rerata ±23,3).
        // asOf 16 Nov → 20 hari sejak kunjungan: ≥ ambang tetap 14, tetapi < 1,5 × 23,3 = 35.
        $f = $this->fixture();
        $this->visitsOn($f, ['2026-08-18', '2026-09-08', '2026-09-29', '2026-10-27']);
        $row = $this->row($f);
        $this->assertSame(20, $row['days_since_visit']);
        $this->assertGreaterThanOrEqual(14, $row['days_since_visit'], 'Sudah melewati ambang tetap 14 hari…');
        $this->assertGreaterThan(17, (float) $row['avg_visit_gap_days'], '…tetapi ritmenya jarang (rerata jarak > 17 hari).');
        $this->assertStringNotContainsString('Kunjungi', implode(' ', $row['suggestions']), 'Ambang relatif ≥ 1,5 × rerata jarak, bukan 14 hari tetap.');
    }

    public function test_short_history_uses_fixed_threshold_without_rhythm_text(): void
    {
        $f = $this->fixture();
        // Hanya 2 kunjungan → 1 jarak (< revisit_min_gaps = 3) → ambang tetap 14 hari, tanpa "(biasanya …)".
        $this->visitsOn($f, ['2026-10-08', '2026-10-15']);
        $row = $this->row($f);
        $this->assertSame(32, $row['days_since_visit'], '15 Okt → 16 Nov = 32 hari ≥ ambang tetap 14.');
        $this->assertContains('Kunjungi — sudah 32 hari.', $row['suggestions']);
        foreach ($row['suggestions'] as $s) {
            $this->assertStringNotContainsString('biasanya', $s);
        }
        // Di bawah ambang tetap: tidak disarankan.
        config(['kiosk_insights.revisit_days' => 60]);
        $this->assertStringNotContainsString('Kunjungi', implode(' ', $this->row($f)['suggestions']));
    }

    public function test_price_label_drops_decimals_when_per_unit_price_is_whole(): void
    {
        $f = $this->fixture(); // 12000 / 15 biji = 800 tepat
        $this->assertStringContainsString('Rp 800/biji', $this->row($f)['price_label']);
        $this->assertStringNotContainsString('800,00', $this->row($f)['price_label']);
        $f['variant']->update(['sale_price_per_pack' => 12500, 'units_per_pack' => 15]);
        $f['variant']->defaultPriceTier?->update(['sale_price_per_pack' => 12500]);
        $this->assertStringContainsString('Rp 833,33/biji', $this->row($f)['price_label']);
    }

    public function test_revenue_and_sold_match_trip_aggregator_for_same_completed_collectors(): void
    {
        $f = $this->fixture();
        $one = $this->settled($f, '2026-10-01', '2026-10-08', 15, [], ['amount_paid' => 5000]);
        $two = $this->settled($f, '2026-10-09', '2026-10-10', 30, ['delivery_type' => 'cash_sale']);
        $aggregate = collect(TripAggregator::for([$one['collector']->id, $two['collector']->id], 1000));
        $row = $this->row($f);
        $this->assertSame((float) $aggregate->sum('omset'), $row['revenue_total']);
        $this->assertSame((int) $aggregate->sum('biji_terjual'), $row['sold_units']);
    }

    public function test_config_reads_numeric_thresholds_and_custom_label_from_environment(): void
    {
        $old = $_ENV['KIOSK_INSIGHTS_MIN_SETTLEMENTS'] ?? null;
        $oldServer = $_SERVER['KIOSK_INSIGHTS_MIN_SETTLEMENTS'] ?? null;
        $_ENV['KIOSK_INSIGHTS_MIN_SETTLEMENTS'] = '4';
        $_SERVER['KIOSK_INSIGHTS_MIN_SETTLEMENTS'] = '4';
        try {
            $rules = require config_path('kiosk_insights.php');
            $this->assertSame(4, $rules['min_settlements']);
            $this->assertIsFloat($rules['slow_sell_through_pct']);
            $this->assertIsFloat($rules['frequent_sold_out_ratio']);
        } finally {
            if ($old === null) {
                unset($_ENV['KIOSK_INSIGHTS_MIN_SETTLEMENTS']);
            } else {
                $_ENV['KIOSK_INSIGHTS_MIN_SETTLEMENTS'] = $old;
            }
            if ($oldServer === null) {
                unset($_SERVER['KIOSK_INSIGHTS_MIN_SETTLEMENTS']);
            } else {
                $_SERVER['KIOSK_INSIGHTS_MIN_SETTLEMENTS'] = $oldServer;
            }
        }
    }

    public function test_profile_requires_explicit_owner_and_valid_date(): void
    {
        $f = $this->fixture();
        $this->artisan('kiosks:insight-profile')->assertFailed();
        $this->artisan('kiosks:insight-profile', ['--owner' => $f['owner']->id, '--as-of' => '2026-02-31'])->assertFailed();
        $this->artisan('kiosks:insight-profile', ['--owner' => $f['owner']->id])->assertSuccessful();
    }
}
