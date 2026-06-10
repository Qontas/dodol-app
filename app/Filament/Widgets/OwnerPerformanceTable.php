<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\Models\Kiosk;
use App\Models\Trip;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class OwnerPerformanceTable extends BaseWidget
{
    protected static ?string $heading = 'Leaderboard Owner Hari Ini';

    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                User::query()->where('role', 'owner')
            )
            ->columns([
                TextColumn::make('name')
                    ->label('Nama Owner')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('kios_aktif')
                    ->label('Kios Aktif')
                    ->state(function (User $record) {
                        return Kiosk::where('is_active', true)
                            ->whereHas('cluster', fn($q) => $q->where('owner_id', $record->id))
                            ->count();
                    }),

                TextColumn::make('trip_hari_ini')
                    ->label('Trip Hari Ini')
                    ->state(function (User $record) {
                        return Trip::where('owner_id', $record->id)
                            ->whereDate('trip_date', today())
                            ->count();
                    }),

                TextColumn::make('omset_hari_ini')
                    ->label('Omset Hari Ini')
                    ->state(function (User $record) {
                        $trips = Trip::where('owner_id', $record->id)
                            ->whereDate('trip_date', today())
                            ->get();
                        return 'Rp ' . number_format($trips->sum('omset_val'), 0, ',', '.');
                    }),

                TextColumn::make('komisi_operator_hari_ini')
                    ->label('Komisi Operator Hari Ini')
                    ->state(function (User $record) {
                        $trips = Trip::where('owner_id', $record->id)
                            ->whereDate('trip_date', today())
                            ->get();
                        return 'Rp ' . number_format($trips->sum('komisi_rian'), 0, ',', '.');
                    }),

                TextColumn::make('untung_bersih_hari_ini')
                    ->label('Untung Bersih Hari Ini')
                    ->state(function (User $record) {
                        $trips = Trip::where('owner_id', $record->id)
                            ->whereDate('trip_date', today())
                            ->get();
                        return 'Rp ' . number_format($trips->sum('untung_bersih_owner'), 0, ',', '.');
                    }),

                TextColumn::make('total_trip_bulan_ini')
                    ->label('Total Trip Bulan Ini')
                    ->state(function (User $record) {
                        return Trip::where('owner_id', $record->id)
                            ->whereMonth('trip_date', now()->month)
                            ->whereYear('trip_date', now()->year)
                            ->count();
                    }),

                TextColumn::make('omset_bulan_ini')
                    ->label('Omset Bulan Ini')
                    ->state(function (User $record) {
                        $trips = Trip::where('owner_id', $record->id)
                            ->whereMonth('trip_date', now()->month)
                            ->whereYear('trip_date', now()->year)
                            ->get();
                        return 'Rp ' . number_format($trips->sum('omset_val'), 0, ',', '.');
                    }),
            ]);
    }
}
