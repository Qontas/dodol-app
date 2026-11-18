<?php

namespace App\Support;

use App\Models\Kiosk;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Read-only, bulk business metrics. No accessors in a kiosk loop and no business writes. */
class KioskInsights
{
    public static function build(?int $ownerId, ?Carbon $asOf = null): array
    {
        self::authorize($ownerId);
        $asOf = ($asOf ?? now())->copy()->endOfDay();
        $date = $asOf->toDateString();
        $since = $asOf->copy()->subDays(29)->startOfDay()->toDateTimeString();
        $rules = config('kiosk_insights');
        $kiosks = Kiosk::query()->excludeWalkInSentinel()
            ->whereHas('cluster.owner', fn ($q) => $q->where('role', 'owner'))
            ->when($ownerId !== null, fn ($q) => $q->whereHas('cluster', fn ($c) => $c->where('owner_id', $ownerId)))
            ->with(['cluster.owner', 'priceTier.productVariant'])->orderBy('id')->get();
        $ids = $kiosks->pluck('id')->all();
        $variants = ProductVariant::query()->where('is_active', true)
            ->whereHas('product', fn ($q) => $q->when($ownerId !== null, fn ($p) => $p->where('owner_id', $ownerId)))
            ->with(['product', 'defaultPriceTier'])->orderBy('id')->get()->groupBy('product.owner_id')->map->first();

        // Source sentinel stock is real stock; source archived stock is not pending.
        $stock = DB::table('deliveries as d')->join('trips as t', 't.id', '=', 'd.trip_id')
            ->leftJoin('settlements as s', 's.delivery_id', '=', 'd.id')
            ->whereIn('d.kiosk_id', $ids)->whereNull('t.deleted_at')->where('d.created_at', '<=', $asOf)
            ->whereNull('s.id')->groupBy('d.kiosk_id')->selectRaw('d.kiosk_id, SUM(d.qty_delivered) as packs, SUM(d.qty_delivered * d.unit_price) as value')->get()->keyBy('kiosk_id');
        $cycleDate = "CASE WHEN d.source_type = 'owner_placed' THEN DATE(d.created_at) WHEN d.source_type = 'migration' THEN COALESCE(k.first_titip_date, DATE(d.created_at)) ELSE t.trip_date END";
        $stockAges = DB::table('deliveries as d')->join('trips as t', 't.id', '=', 'd.trip_id')->join('kiosks as k', 'k.id', '=', 'd.kiosk_id')
            ->leftJoin('settlements as s', 's.delivery_id', '=', 'd.id')->whereIn('d.kiosk_id', $ids)->whereNull('t.deleted_at')
            ->where('d.created_at', '<=', $asOf)->whereNull('s.id')->groupBy('d.kiosk_id')
            ->selectRaw("d.kiosk_id, MIN($cycleDate) as oldest")->get()->keyBy('kiosk_id');
        // Distinct delivery IDs, not visit rows: extensions/checks can reference a delivery twice.
        $collected = DB::table('kiosk_visits as kv')->join('trips as ct', 'ct.id', '=', 'kv.trip_id')->join('users as op', 'op.id', '=', 'ct.operator_id')
            ->whereNull('kv.corrected_at')->whereNotNull('kv.settled_delivery_id')->whereNull('ct.deleted_at')->whereNotNull('ct.ended_at')
            ->where('ct.trip_date', '<=', $date)->where('kv.visited_at', '<=', $asOf)->whereIn('kv.kiosk_id', $ids)
            ->where('op.email', 'not like', User::SYSTEM_OPERATOR_EMAIL_PREFIX.'%')
            ->groupBy('kv.settled_delivery_id')->selectRaw('kv.settled_delivery_id, MIN(ct.trip_date) as collection_date, MIN(kv.visited_at) as collection_at');
        $debt = DB::table('settlements as s')->join('deliveries as d', 'd.id', '=', 's.delivery_id')
            ->joinSub(clone $collected, 'c', 'c.settled_delivery_id', '=', 'd.id')
            ->whereIn('d.kiosk_id', $ids)->where('s.status', 'pending')->where('s.visit_date', '<=', $date)
            ->whereColumn('s.amount_due', '>', 's.amount_paid')->groupBy('d.kiosk_id')
            ->selectRaw('d.kiosk_id, SUM(GREATEST(s.amount_due - s.amount_paid, 0)) as amount, MIN(s.visit_date) as oldest')->get()->keyBy('kiosk_id');
        $sales = DB::table('deliveries as d')->joinSub($collected, 'c', 'c.settled_delivery_id', '=', 'd.id')
            ->join('settlements as s', 's.delivery_id', '=', 'd.id')->join('trips as t', 't.id', '=', 'd.trip_id')->join('kiosks as k', 'k.id', '=', 'd.kiosk_id')
            ->whereIn('d.kiosk_id', $ids)->where('s.visit_date', '<=', $date)->groupBy('d.kiosk_id')
            ->selectRaw("d.kiosk_id, SUM(s.qty_sold) as sold, SUM(s.amount_paid) as revenue,
                SUM(CASE WHEN c.collection_date >= ? THEN s.amount_paid ELSE 0 END) as revenue_30,
                SUM(CASE WHEN d.delivery_type = 'cash_sale' THEN s.qty_sold ELSE 0 END) as cash_sold,
                SUM(CASE WHEN d.delivery_type = 'consignment' THEN 1 ELSE 0 END) as settled_count,
                SUM(CASE WHEN d.delivery_type = 'consignment' THEN s.qty_sold ELSE 0 END) as consignment_sold,
                SUM(CASE WHEN d.delivery_type = 'consignment' THEN d.qty_delivered * COALESCE(d.units_per_pack_snapshot, 15) ELSE 0 END) as settled_units,
                SUM(CASE WHEN d.delivery_type = 'consignment' THEN s.qty_returned_fresh ELSE 0 END) as fresh_returns,
                SUM(CASE WHEN d.delivery_type = 'consignment' THEN s.qty_returned_expired ELSE 0 END) as bs,
                SUM(CASE WHEN d.delivery_type = 'consignment' AND s.qty_sold >= 0.95 * d.qty_delivered * COALESCE(d.units_per_pack_snapshot, 15) AND d.qty_delivered > 0 THEN 1 ELSE 0 END) as sold_out,
                SUM(CASE WHEN d.delivery_type = 'consignment' THEN GREATEST(DATEDIFF(s.visit_date, $cycleDate), 1) ELSE 0 END) as cycle_days", [$since])->get()->keyBy('kiosk_id');
        // Rank eligible, distinct consignment settlements, not visits or lifetime sold-out totals.
        $ranked = DB::table('deliveries as d')->joinSub(clone $collected, 'c', 'c.settled_delivery_id', '=', 'd.id')
            ->join('settlements as s', 's.delivery_id', '=', 'd.id')
            ->whereIn('d.kiosk_id', $ids)->where('d.delivery_type', 'consignment')->where('s.visit_date', '<=', $date)
            ->selectRaw('d.kiosk_id, CASE WHEN d.qty_delivered > 0 AND s.qty_sold >= 0.95 * d.qty_delivered * COALESCE(d.units_per_pack_snapshot, 15) THEN 1 ELSE 0 END as sold_out,
                ROW_NUMBER() OVER (PARTITION BY d.kiosk_id ORDER BY c.collection_date DESC, c.collection_at DESC, d.id DESC) as rn');
        $recent = DB::query()->fromSub($ranked, 'r')->where('rn', '<=', 3)->groupBy('kiosk_id')
            ->selectRaw('kiosk_id, COUNT(*) as count, SUM(sold_out) as sold_out')->get()->keyBy('kiosk_id');
        $delivered = DB::table('deliveries as d')->join('trips as t', 't.id', '=', 'd.trip_id')
            ->whereIn('d.kiosk_id', $ids)->whereNull('t.deleted_at')->where('d.created_at', '<=', $asOf)
            ->groupBy('d.kiosk_id')->selectRaw('d.kiosk_id, SUM(d.qty_delivered) as packs')->pluck('packs', 'kiosk_id');

        // One grouped row per real trip/kiosk, union visit-less drops. Sentinel is never a visit.
        $visitBase = DB::table('kiosk_visits as kv')->whereIn('kv.kiosk_id', $ids)->whereNull('kv.corrected_at')->where('kv.visited_at', '<=', $asOf)
            ->groupBy('kv.trip_id', 'kv.kiosk_id')->selectRaw('kv.trip_id, kv.kiosk_id, MIN(kv.visited_at) as visited_at');
        $dropBase = DB::table('deliveries as d')->whereIn('d.kiosk_id', $ids)->whereNull('d.kiosk_visit_id')->where('d.created_at', '<=', $asOf)
            ->groupBy('d.trip_id', 'd.kiosk_id')->selectRaw('d.trip_id, d.kiosk_id, MIN(d.created_at) as visited_at');
        $visits = DB::query()->fromSub($visitBase->unionAll($dropBase), 'v')->join('trips as t', 't.id', '=', 'v.trip_id')->join('users as op', 'op.id', '=', 't.operator_id')
            ->whereNull('t.deleted_at')->where('t.trip_date', '<=', $date)->where('op.email', 'not like', User::SYSTEM_OPERATOR_EMAIL_PREFIX.'%')
            ->groupBy('v.kiosk_id', 'v.trip_id', 'op.id', 'op.name')
            ->selectRaw('v.kiosk_id, v.trip_id, op.id as operator_id, op.name as operator_name, MIN(v.visited_at) as visited_at')
            ->orderBy('visited_at')->orderBy('v.trip_id')->get()->groupBy('kiosk_id');

        $rows = [];
        foreach ($kiosks as $kiosk) {
            $id = $kiosk->id;
            $owner = $kiosk->cluster->owner;
            $variant = $variants->get($owner->id);
            $price = $variant ? KioskPricing::pricePerPack($kiosk, $variant) : 0;
            $units = max(1, (int) ($variant?->units_per_pack ?? 15));
            $tier = $kiosk->priceTier;
            if (! $tier || (int) $tier->product_variant_id !== (int) $variant?->id || (int) $tier->owner_id !== (int) $owner->id) {
                $tier = $variant?->defaultPriceTier;
            }
            $perUnit = $price / $units;
            $priceLabel = ($tier?->name ?? $variant?->name ?? 'Standar').' — Rp '.number_format($price, 0, ',', '.').'/mika · Rp '
                .number_format($perUnit, $perUnit == floor($perUnit) ? 0 : 2, ',', '.').'/biji';
            $s = $sales->get($id);
            $v = $visits->get($id, collect());
            $last = $v->last();
            $counts = $v->groupBy('operator_id')->map(fn ($items) => ['count' => $items->count(), 'id' => $items->first()->operator_id, 'name' => $items->first()->operator_name])->sort(fn ($a, $b) => ($b['count'] <=> $a['count']) ?: ($a['id'] <=> $b['id']));
            $gaps = [];
            $previousVisit = null;
            foreach ($v as $visit) {
                if ($previousVisit !== null) {
                    $gaps[] = abs(Carbon::parse($previousVisit)->diffInDays(Carbon::parse($visit->visited_at)));
                }
                $previousVisit = $visit->visited_at;
            }
            $settled = (int) ($s?->settled_count ?? 0);
            $settledUnits = (int) ($s?->settled_units ?? 0);
            $sold = (int) ($s?->consignment_sold ?? 0);
            $consignment = ! $kiosk->is_cash_only && ! $kiosk->isBooking();
            $rows[] = [
                'id' => $id, 'owner_id' => $owner->id, 'business_name' => $owner->getBusinessNameValue(),
                'area' => $kiosk->cluster->name, 'name' => $kiosk->name, 'owner_name' => $kiosk->owner_name, 'phone' => $kiosk->phone,
                'address' => $kiosk->location_description, 'maps_address' => $kiosk->maps_address,
                'latitude' => $kiosk->latitude, 'longitude' => $kiosk->longitude,
                'maps_url' => KioskInsightsSummary::coordinateMapsUrl($kiosk->latitude, $kiosk->longitude) ?? $kiosk->maps_url,
                'type' => $kiosk->is_cash_only ? 'Cash' : ($kiosk->isBooking() ? 'Booking' : 'Konsinyasi'),
                'price_label' => $priceLabel, 'price_per_pack' => $price, 'units_per_pack' => $units,
                'default_qty_mika' => $kiosk->default_qty_mika, 'is_active' => $kiosk->is_active, 'first_titip_date' => $kiosk->first_titip_date?->toDateString(),
                'notes' => $kiosk->store_note ?: $kiosk->notes, 'frequent_operator' => $counts->first()['name'] ?? null,
                'visit_count' => $v->count(), 'visits_30' => $v->filter(fn ($row) => $row->visited_at >= $since)->count(),
                'last_visit_at' => $last?->visited_at, 'last_operator' => $last?->operator_name,
                'days_since_visit' => $last ? self::age($last->visited_at, $asOf) : null,
                'avg_visit_gap_days' => $gaps ? round(array_sum($gaps) / count($gaps), 2) : null,
                'delivered_packs' => (int) ($delivered[$id] ?? 0), 'sold_units' => (int) ($s?->sold ?? 0), 'cash_sold_units' => (int) ($s?->cash_sold ?? 0),
                'sell_through_pct' => $consignment && $settledUnits > 0 ? min(100, round(100 * $sold / $settledUnits, 2)) : null,
                'weekly_sales' => $consignment && ($s?->cycle_days ?? 0) > 0 ? round(7 * $sold / $s->cycle_days, 2) : null,
                'fresh_return_pct' => $consignment && $settledUnits > 0 ? min(100, round(100 * $s->fresh_returns / $settledUnits, 2)) : null,
                'bs_pct' => $consignment && $settledUnits > 0 ? min(100, round(100 * $s->bs / $settledUnits, 2)) : null,
                'revenue_total' => (float) ($s?->revenue ?? 0), 'revenue_30' => (float) ($s?->revenue_30 ?? 0),
                'avg_revenue_per_visit' => $v->count() ? round((float) ($s?->revenue ?? 0) / $v->count(), 2) : 0,
                'sold_out_count' => (int) ($s?->sold_out ?? 0), 'settled_count' => $settled,
                'recent_settled_count' => (int) ($recent[$id]->count ?? 0), 'recent_sold_out_count' => (int) ($recent[$id]->sold_out ?? 0),
                'consignment_sold_units' => $sold, 'settled_units' => $settledUnits,
                'pending_packs' => (int) ($stock[$id]->packs ?? 0), 'pending_value' => (float) ($stock[$id]->value ?? 0),
                'pending_age_days' => isset($stockAges[$id]) ? self::age($stockAges[$id]->oldest, $asOf) : null,
                'receivable' => (float) ($debt[$id]->amount ?? 0), 'receivable_age_days' => isset($debt[$id]) ? self::age($debt[$id]->oldest, $asOf) : null,
                'photo_path' => $kiosk->photo_path, 'photo_status' => $kiosk->photo_path ? 'pending' : 'Tidak ada foto', 'thumbnail_path' => null,
            ];
        }
        $peerRates = [];
        foreach ($rows as $row) {
            if ($row['type'] === 'Konsinyasi' && $row['settled_count'] >= $rules['min_settlements'] && $row['weekly_sales'] !== null) {
                $peerRates[$row['owner_id']][] = $row['weekly_sales'];
            }
        }
        $ranks = [];
        foreach ($peerRates as $owner => $rates) {
            sort($rates, SORT_NUMERIC);
            foreach ($rates as $index => $rate) {
                // Assign ties their shared upper empirical-CDF percentile, in O(n log n).
                $ranks[$owner][(string) $rate] = round(100 * ($index + 1) / count($rates), 2);
            }
        }
        foreach ($rows as &$row) {
            $row['rate_percentile'] = $row['weekly_sales'] !== null ? ($ranks[$row['owner_id']][(string) $row['weekly_sales']] ?? null) : null;
            self::classify($row, $rules);
        }
        unset($row);
        usort($rows, fn ($a, $b) => strnatcasecmp($a['business_name'], $b['business_name']) ?: ($a['owner_id'] <=> $b['owner_id']) ?: strnatcasecmp($a['area'], $b['area']) ?: strnatcasecmp($a['name'], $b['name']) ?: ($a['id'] <=> $b['id']));
        $observedDates = $visits->flatten(1)->pluck('visited_at')->filter()->all();
        foreach ($kiosks as $kiosk) {
            if ($kiosk->first_titip_date) {
                $observedDates[] = $kiosk->first_titip_date->toDateString();
            }
        }
        sort($observedDates);

        return ['generated_at' => now()->toIso8601String(), 'as_of' => $date, 'business_name' => $ownerId !== null ? ($kiosks->first()?->cluster?->owner?->getBusinessNameValue() ?? User::find($ownerId)?->getBusinessNameValue() ?? 'Usaha') : 'Semua Usaha', 'all_owners' => $ownerId === null, 'period_start' => $observedDates ? substr($observedDates[0], 0, 10) : null, 'rules' => $rules, 'rows' => $rows];
    }

    private static function authorize(?int $ownerId): void
    {
        $user = auth()->user();
        if ($user !== null) {
            abort_unless($user->role === 'super_admin' || ($user->role === 'owner' && $ownerId === (int) $user->id), 403);
        } else {
            abort_unless(app()->runningInConsole() && $ownerId !== null, 403);
        }
        if ($ownerId !== null) {
            abort_unless(User::query()->whereKey($ownerId)->where('role', 'owner')->exists(), 404);
        }
    }

    private static function age(string $date, Carbon $asOf): int
    {
        return max(0, (int) Carbon::parse($date)->startOfDay()->diffInDays($asOf->copy()->startOfDay()));
    }

    private static function classify(array &$row, array $rules): void
    {
        $enough = $row['type'] === 'Konsinyasi' && $row['settled_count'] >= $rules['min_settlements'];
        $problem = ($row['bs_pct'] ?? 0) >= $rules['problem_bs_pct'] || ($row['receivable'] > 0 && $row['receivable_age_days'] >= $rules['debt_age_days']);
        $fast = $enough && $row['sell_through_pct'] >= $rules['fast_sell_through_pct'] && $row['rate_percentile'] >= $rules['fast_percentile'];
        $inactive = ! $row['is_active'] || ($row['days_since_visit'] !== null && $row['days_since_visit'] > $rules['inactive_days']);
        $key = $inactive ? 'inactive' : (! $enough ? 'insufficient' : ($problem ? 'problem' : ($fast ? 'fast' : ($row['sell_through_pct'] < $rules['slow_sell_through_pct'] ? 'slow' : 'stable'))));
        $row['label_key'] = $key;
        $displayKey = $key === 'problem'
            ? ($row['receivable'] > 0 && $row['receivable_age_days'] >= $rules['debt_age_days'] ? 'debt' : 'bs') : $key;
        $row['display_label_key'] = $displayKey;
        $row['label'] = $rules['labels'][$displayKey];
        // Ambang kunjungan relatif ke ritme kedai itu; tanpa cukup riwayat jarak → ambang tetap.
        // Jumlah jarak = jumlah kunjungan − 1 (avg_visit_gap_days dihitung dari jarak antar kunjungan berurutan).
        $rhythm = $row['avg_visit_gap_days'] !== null && ($row['visit_count'] - 1) >= $rules['revisit_min_gaps'] && $row['avg_visit_gap_days'] > 0;
        $revisitAfter = $rhythm
            ? max($rules['revisit_min_days'], $rules['revisit_gap_multiplier'] * $row['avg_visit_gap_days'])
            : $rules['revisit_days'];
        $needsVisit = $row['days_since_visit'] === null || $row['days_since_visit'] >= $revisitAfter;
        $row['priority'] = $row['receivable'] > 0 && $row['receivable_age_days'] >= $rules['debt_age_days'] ? 1
            : ($needsVisit ? 2
                : ($enough && ($row['bs_pct'] ?? 0) >= $rules['problem_bs_pct'] ? 3 : 4));
        $suggestions = [];
        if (! $row['is_active']) {
            $suggestions[] = 'Konfirmasi status kedai nonaktif sebelum pengiriman berikutnya.';
        }
        if ($row['receivable'] > 0) {
            $suggestions[] = 'Ada piutang Rp '.number_format($row['receivable'], 0, ',', '.').' sejak '.$row['receivable_age_days'].' hari. Tagih di kunjungan berikutnya.';
        }
        if ($needsVisit) {
            $suggestions[] = $row['days_since_visit'] === null
                ? 'Belum ada kunjungan tercatat. Jadwalkan kunjungan.'
                : 'Sudah '.$row['days_since_visit'].' hari belum dikunjungi'.($rhythm ? ' (biasanya tiap '.(int) round($row['avg_visit_gap_days']).' hari)' : '').'. Jadwalkan kunjungan.';
        }
        if ($enough && ($row['bs_pct'] ?? 0) >= $rules['problem_bs_pct']) {
            $suggestions[] = 'Banyak dodol rusak/kedaluwarsa ('.KioskInsightsSummary::number($row['bs_pct']).'%). Cek tempat penyimpanan (panas/lembap) atau dodol terlalu lama di kedai.';
        }
        if ($row['days_since_visit'] !== null && $row['days_since_visit'] >= $rules['inactive_days']) {
            $suggestions[] = 'Konfirmasi apakah kedai masih beroperasi; lama tidak dikunjungi bukan bukti penjualan nol.';
        }
        if ($enough && ! $inactive && $row['default_qty_mika'] > 0) {
            $current = (int) $row['default_qty_mika'];
            $estimated = $row['avg_visit_gap_days'] !== null && $row['avg_visit_gap_days'] > 0
                ? max(1, (int) round($row['weekly_sales'] / 7 * $row['avg_visit_gap_days'] / $row['units_per_pack'])) : null;
            $oftenSoldOut = $row['recent_settled_count'] === 3 && $row['recent_sold_out_count'] >= 2;
            if ($oftenSoldOut && ! $problem) {
                // Habis means demand is censored: observed sales are a lower bound, not a demand ceiling.
                // Keep the old estimate/cap, but offer at least +1 even when rounding equals current quota.
                $target = min($current + min(2, max(0, $rules['max_quota_increase'])), max($current + 1, $estimated ?? $current));
                if ($target > $current) {
                    $suggestions[] = 'Sering habis. Coba naikkan jatah dari '.$current.' jadi '.$target.' mika.';
                }
            } elseif (! $oftenSoldOut && $row['sell_through_pct'] < $rules['slow_sell_through_pct'] && $estimated !== null && $estimated < $current) {
                $suggestions[] = 'Kurang laku. Coba turunkan jatah dari '.$current.' jadi '.$estimated.' mika.';
            }
        }
        $row['suggestions'] = $suggestions;
    }
}
