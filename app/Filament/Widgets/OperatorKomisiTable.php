<?php

namespace App\Filament\Widgets;

use App\Models\User;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class OperatorKomisiTable extends BaseWidget
{
    protected static ?string $heading = 'Komisi Operator Bulan Ini';

    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function table(Table $table): Table
    {
        $month = now()->month;
        $year = now()->year;

        return $table
            ->query(
                User::query()
                    ->where('role', 'operator')
                    ->where('is_active', true)
                    ->withCount(['operatedTrips as trip_bulan_ini' => fn (Builder $q) => $q
                        ->whereMonth('trip_date', $month)
                        ->whereYear('trip_date', $year)])
                    ->withSum(['commissions as komisi_bulan_ini' => fn (Builder $q) => $q
                        ->whereMonth('paid_at', $month)
                        ->whereYear('paid_at', $year)], 'commission_amount')
            )
            ->defaultSort('komisi_bulan_ini', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label('Nama Operator')
                    ->searchable(),

                TextColumn::make('owner.name')
                    ->label('Owner')
                    ->default('—'),

                TextColumn::make('trip_bulan_ini')
                    ->label('Trip Bulan Ini')
                    ->sortable(),

                TextColumn::make('komisi_bulan_ini')
                    ->label('Komisi Bulan Ini')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => 'Rp ' . number_format((float) $state, 0, ',', '.')),
            ]);
    }
}
