<?php

namespace App\Filament\Widgets;

use App\Models\Settlement;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class OwnerOmsetChart extends ChartWidget
{
    protected static ?string $heading = 'Tren Omset 30 Hari — Top 3 Owner';

    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        // Rentang 30 hari terakhir (termasuk hari ini).
        $days = collect(range(0, 29))
            ->map(fn ($i) => Carbon::today()->subDays(29 - $i)->toDateString());

        $start = $days->first();

        // Omset harian per owner = SUM(amount_paid) settlement, ditelusuri
        // settlement → delivery → trip → owner.
        $rows = Settlement::query()
            ->join('deliveries', 'settlements.delivery_id', '=', 'deliveries.id')
            ->join('trips', 'deliveries.trip_id', '=', 'trips.id')
            ->join('users', 'trips.owner_id', '=', 'users.id')
            ->whereNull('trips.deleted_at') // join manual → tak kena SoftDeletes global scope
            ->whereDate('settlements.visit_date', '>=', $start)
            ->groupBy('trips.owner_id', 'users.name', 'settlements.visit_date')
            ->selectRaw('trips.owner_id as owner_id, users.name as owner_name, '
                . 'settlements.visit_date as d, SUM(settlements.amount_paid) as total')
            ->get();

        // Top 3 owner berdasarkan total omset 30 hari.
        $topOwners = $rows->groupBy('owner_id')
            ->map(fn ($g) => [
                'name' => $g->first()->owner_name,
                'total' => $g->sum('total'),
            ])
            ->sortByDesc('total')
            ->take(3);

        // Lookup cepat: "ownerId|tanggal" => total.
        $lookup = $rows->keyBy(fn ($r) => $r->owner_id . '|' . $r->d);

        $palette = [
            ['#d97706', 'rgba(217,119,6,0.1)'],
            ['#2563eb', 'rgba(37,99,235,0.1)'],
            ['#059669', 'rgba(5,150,105,0.1)'],
        ];

        $datasets = [];
        $i = 0;
        foreach ($topOwners as $ownerId => $info) {
            [$border, $bg] = $palette[$i] ?? ['#64748b', 'rgba(100,116,139,0.1)'];
            $datasets[] = [
                'label' => $info['name'],
                'data' => $days->map(fn ($d) => (float) ($lookup[$ownerId . '|' . $d]->total ?? 0))->all(),
                'borderColor' => $border,
                'backgroundColor' => $bg,
                'fill' => true,
                'tension' => 0.3,
                'borderWidth' => 2,
            ];
            $i++;
        }

        return [
            'datasets' => $datasets,
            'labels' => $days->map(fn ($d) => Carbon::parse($d)->format('d/m'))->all(),
        ];
    }
}
