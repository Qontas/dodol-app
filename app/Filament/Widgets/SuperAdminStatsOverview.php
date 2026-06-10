<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\Models\Kiosk;
use App\Models\Trip;
use App\Models\Settlement;
use App\Models\Commission;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SuperAdminStatsOverview extends BaseWidget
{
    public static function canView(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    protected function getStats(): array
    {
        $owners = User::where('role', 'owner')->where('is_active', true)->count();
        $operators = User::where('role', 'operator')->where('is_active', true)->count();

        $kiosks = Kiosk::where('is_active', true)->count();

        $trips = Trip::whereDate('trip_date', today())->count();

        $omset = Settlement::whereDate('visit_date', today())->sum('amount_paid');

        $komisi = Commission::whereDate('paid_at', today())->sum('commission_amount');

        // Rata-rata omset per owner aktif (guard pembagian nol).
        $avgOmsetPerOwner = $owners > 0 ? $omset / $owners : 0;

        return [
            Stat::make('Owner & Operator Aktif', $owners + $operators)
                ->description("{$owners} Owner, {$operators} Operator")
                ->icon('heroicon-o-users'),
            Stat::make('Total Kios Terdaftar', $kiosks)
                ->description('Semua kios aktif dari semua owner')
                ->icon('heroicon-o-building-storefront'),
            Stat::make('Total Trip Hari Ini', $trips)
                ->icon('heroicon-o-truck'),
            Stat::make('Total Omset Global Hari Ini', 'Rp ' . number_format($omset, 0, ',', '.'))
                ->icon('heroicon-o-currency-dollar'),
            Stat::make('Rata-rata Omset per Owner Hari Ini', 'Rp ' . number_format($avgOmsetPerOwner, 0, ',', '.'))
                ->description("Dibagi {$owners} owner aktif")
                ->icon('heroicon-o-scale'),
            Stat::make('Total Komisi Operator Global Hari Ini', 'Rp ' . number_format($komisi, 0, ',', '.'))
                ->icon('heroicon-o-gift'),
        ];
    }
}
