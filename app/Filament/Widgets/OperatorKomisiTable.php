<?php

namespace App\Filament\Widgets;

use App\Models\Trip;
use App\Models\User;
use App\Support\TripAggregator;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class OperatorKomisiTable extends BaseWidget
{
    protected static ?string $heading = 'Komisi Operator Bulan Ini';

    protected int | string | array $columnSpan = 'full';

    /** @var array<int, int> operator_id => komisi bulan ini, dihitung SEKALI di table(). */
    private array $komisiByOperator = [];

    public static function canView(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function table(Table $table): Table
    {
        $month = now()->month;
        $year = now()->year;

        $query = User::query()
            ->where('role', 'operator')
            ->where('is_active', true)
            ->withCount(['operatedTrips as trip_bulan_ini' => fn (Builder $q) => $q
                ->whereMonth('trip_date', $month)
                ->whereYear('trip_date', $year)]);

        $this->komisiByOperator = $this->computeKomisiByOperator($query->get(['id']), $month, $year);

        return $table
            ->query($query)
            ->defaultSort('trip_bulan_ini', 'desc')
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
                    ->state(fn (User $record): string => 'Rp '.number_format($this->komisiByOperator[$record->id] ?? 0, 0, ',', '.')),
            ]);
    }

    /**
     * 🔴 DIROMBAK 24 Okt 2026 (BRIEF "Satu Sumber Kebenaran Komisi", fix G) — dulu
     * withSum(['commissions as komisi_bulan_ini' => ... whereMonth/whereYear('paid_at')],
     * 'commission_amount'): tabel `commissions` memakai rumus reverse-engineer
     * omset×margin×tarif% (beda rounding tipis dari komisi_rian snapshot), DAN
     * 'paid_at' = waktu trip DIAKHIRI, bukan trip_date — operator yang trip-nya
     * dimulai bulan ini tapi berakhir bulan depan tak pernah terhitung "bulan ini".
     * Sekarang: SATU query trip (realOperational, trip_date bulan ini, lintas semua
     * operator) + TripAggregator::for() SATU KALI, dijumlahkan per operator_id di
     * PHP — identik pola OwnerPerformanceTable::computeStatsByOwner(). Jumlah query
     * KONSTAN berapa pun banyak operator (bukan N+1 per baris).
     *
     * @return array<int, int> operator_id => komisi (int, dibulatkan)
     */
    private function computeKomisiByOperator($operators, int $month, int $year): array
    {
        $operatorIds = $operators->pluck('id')->all();
        $out = array_fill_keys($operatorIds, 0);

        if (empty($operatorIds)) {
            return $out;
        }

        $trips = Trip::whereIn('operator_id', $operatorIds)
            ->whereMonth('trip_date', $month)
            ->whereYear('trip_date', $year)
            ->realOperational()
            ->get(['id', 'operator_id']);

        $agg = TripAggregator::for($trips->pluck('id')->all(), 1000);

        foreach ($trips as $trip) {
            $out[$trip->operator_id] = ($out[$trip->operator_id] ?? 0) + (int) round($agg[$trip->id]['komisi'] ?? 0);
        }

        return $out;
    }
}
