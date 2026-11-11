<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Batch application fees, separate from sales/HPP/operator commission.
 * Normal drops use their trip date and frozen trip tariff. Owner-placed drops
 * use their own creation date and delivery tariff, even on the shared sentinel.
 * Migration, BS redistribution and archived trips never generate fees.
 */
class AppFeeCalculator
{
    /** @return array<int, array<string, mixed>> keyed by owner_id */
    public static function summaryForOwners(): array
    {
        $owners = User::query()->where('role', 'owner')->get(['id', 'name', 'app_fee_per_pack']);
        $out = [];
        foreach ($owners as $owner) {
            $out[$owner->id] = [
                'owner_id' => $owner->id,
                'owner_name' => $owner->name,
                'rate' => $owner->getAppFeePerPackValue(),
                'mika_today' => 0,
                'mika_month' => 0,
                'mika_total' => 0,
                'fee_today' => 0.0,
                'fee_month' => 0.0,
                'fee_total' => 0.0,
            ];
        }
        if ($owners->isEmpty()) {
            return $out;
        }

        $today = today()->toDateString();
        $monthStart = today()->startOfMonth()->toDateString();
        $monthEnd = today()->endOfMonth()->toDateString();
        $rows = DB::query()->fromSub(self::feeDeliveries(), 'f')
            ->groupBy('owner_id')
            ->selectRaw(
                'owner_id,'.
                'COALESCE(SUM(CASE WHEN fee_date = ? THEN qty_delivered ELSE 0 END), 0) as mika_today,'.
                'COALESCE(SUM(CASE WHEN fee_date BETWEEN ? AND ? THEN qty_delivered ELSE 0 END), 0) as mika_month,'.
                'COALESCE(SUM(qty_delivered), 0) as mika_total,'.
                'COALESCE(SUM(CASE WHEN fee_date = ? THEN qty_delivered * rate ELSE 0 END), 0) as fee_today,'.
                'COALESCE(SUM(CASE WHEN fee_date BETWEEN ? AND ? THEN qty_delivered * rate ELSE 0 END), 0) as fee_month,'.
                'COALESCE(SUM(qty_delivered * rate), 0) as fee_total',
                [$today, $monthStart, $monthEnd, $today, $monthStart, $monthEnd]
            )->get();

        foreach ($rows as $row) {
            if (! isset($out[$row->owner_id])) {
                continue;
            }
            foreach (['mika_today', 'mika_month', 'mika_total'] as $key) {
                $out[$row->owner_id][$key] = (int) $row->$key;
            }
            foreach (['fee_today', 'fee_month', 'fee_total'] as $key) {
                $out[$row->owner_id][$key] = (float) $row->$key;
            }
        }

        return $out;
    }

    /** @return array<int, array{date: string, owner_id: int, owner_name: string, mika_kena_fee: int, fee: float}> */
    public static function summaryByOwnerAndDate(int $year, int $month): array
    {
        $monthStart = \Illuminate\Support\Carbon::create($year, $month, 1)->startOfMonth();
        $monthEnd = (clone $monthStart)->endOfMonth();
        $rows = DB::query()->fromSub(self::feeDeliveries(), 'f')
            ->join('users as o', 'o.id', '=', 'f.owner_id')
            ->whereBetween('f.fee_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->groupBy('f.fee_date', 'f.owner_id', 'o.name')
            ->selectRaw('f.fee_date, f.owner_id, o.name as owner_name, SUM(f.qty_delivered) as mika_kena_fee, SUM(f.qty_delivered * f.rate) as fee')
            ->orderBy('f.fee_date')->orderBy('o.name')->get();

        return $rows->map(fn ($row) => [
            'date' => (string) $row->fee_date,
            'owner_id' => (int) $row->owner_id,
            'owner_name' => $row->owner_name,
            'mika_kena_fee' => (int) $row->mika_kena_fee,
            'fee' => (float) $row->fee,
        ])->all();
    }

    private static function feeDeliveries(): Builder
    {
        // Laravel writes these naive DATETIME timestamps in config('app.timezone')
        // (Model::freshTimestamp/asDateTime), not UTC-normalized ISO serialization.
        // DATE(created_at) therefore already represents the app-local creation day.
        return DB::table('deliveries as d')
            ->join('trips as t', 't.id', '=', 'd.trip_id')
            ->join('users as op', 'op.id', '=', 't.operator_id')
            ->where('d.delivery_type', '<>', 'bs_redistribution')
            ->where('d.source_type', '<>', 'migration')
            ->whereNull('t.deleted_at')
            ->where(function (Builder $query) {
                $query->where('d.source_type', 'owner_placed')
                    ->orWhere('op.email', 'not like', User::SYSTEM_OPERATOR_EMAIL_PREFIX.'%');
            })
            ->selectRaw("t.owner_id, d.qty_delivered,
                CASE WHEN d.source_type = 'owner_placed' THEN DATE(d.created_at) ELSE t.trip_date END as fee_date,
                CASE WHEN d.source_type = 'owner_placed' THEN COALESCE(d.app_fee_per_pack_snapshot, 0)
                     ELSE COALESCE(t.app_fee_per_pack_snapshot, 0) END as rate");
    }
}
