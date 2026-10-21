<?php

namespace App\Filament\Widgets;

use App\Models\Trip;
use App\Models\User;
use App\Support\TripAggregator;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Collection;

/**
 * BRIEF 2 Section 4 — ringkasan PER OWNER untuk dashboard admin.
 *
 * 🔴 DIROMBAK 21 Okt 2026 — versi sebelumnya menembak 7 query TERPISAH PER BARIS
 * owner (Kiosk::count + Trip::count + 3× Trip::get()->sum() + Trip::count +
 * Trip::get()->sum()), jadi makin banyak owner makin lambat (N×7). Sekarang DUA
 * query owner-level (daftar owner + withCount, daftar trip bulan ini) ditambah
 * TripAggregator::for() SATU KALI untuk SEMUA trip SEMUA owner sekaligus (jumlah
 * query TripAggregator KONSTAN — lihat docblock-nya), lalu dikelompokkan per
 * owner di PHP. Total: 2 + konstanta internal TripAggregator (~7) = ~9 query
 * BERAPA PUN BANYAK OWNER (diukur: 9 query untuk 1 owner MAUPUN 5 owner — lihat
 * OwnerPerformanceTableQueryCountTest).
 *
 * omset/komisi/untung_bersih dibaca dari TripAggregator — rumus IDENTIK accessor
 * Trip (termasuk komisi_per_mika_snapshot per trip, BRIEF 2 Section 2), bukan
 * tarif owner LIVE lagi.
 */
class OwnerPerformanceTable extends BaseWidget
{
    protected static ?string $heading = 'Leaderboard Owner Hari Ini';

    protected int | string | array $columnSpan = 'full';

    /** @var array<int, array<string, mixed>> owner_id => metrik, dihitung SEKALI di table(). */
    private array $statsByOwner = [];

    public static function canView(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function table(Table $table): Table
    {
        $owners = User::query()->where('role', 'owner')
            ->withCount([
                'operators',
                'ownedKiosks as active_kiosks_count' => fn ($q) => $q->where('kiosks.is_active', true),
            ])
            ->get();

        $this->statsByOwner = $this->computeStatsByOwner($owners);

        return $table
            ->query(User::query()->whereKey($owners->pluck('id')))
            ->columns([
                TextColumn::make('name')
                    ->label('Nama Owner')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('operators_count')
                    ->label('Operator')
                    ->state(fn (User $record): int => $this->statsByOwner[$record->id]['operators_count'] ?? 0),

                TextColumn::make('kios_aktif')
                    ->label('Kios Aktif')
                    ->state(fn (User $record): int => $this->statsByOwner[$record->id]['active_kiosks_count'] ?? 0),

                TextColumn::make('trip_hari_ini')
                    ->label('Trip Hari Ini')
                    ->state(fn (User $record): int => $this->statsByOwner[$record->id]['trip_hari_ini'] ?? 0),

                TextColumn::make('omset_hari_ini')
                    ->label('Omset Hari Ini')
                    ->state(fn (User $record): string => 'Rp '.number_format($this->statsByOwner[$record->id]['omset_hari_ini'] ?? 0, 0, ',', '.')),

                TextColumn::make('komisi_operator_hari_ini')
                    ->label('Komisi Operator Hari Ini')
                    ->state(fn (User $record): string => 'Rp '.number_format($this->statsByOwner[$record->id]['komisi_hari_ini'] ?? 0, 0, ',', '.')),

                TextColumn::make('untung_bersih_hari_ini')
                    ->label('Untung Bersih Hari Ini')
                    ->state(fn (User $record): string => 'Rp '.number_format($this->statsByOwner[$record->id]['untung_bersih_hari_ini'] ?? 0, 0, ',', '.')),

                TextColumn::make('total_trip_bulan_ini')
                    ->label('Total Trip Bulan Ini')
                    ->state(fn (User $record): int => $this->statsByOwner[$record->id]['total_trip_bulan_ini'] ?? 0),

                TextColumn::make('omset_bulan_ini')
                    ->label('Omset Bulan Ini')
                    ->state(fn (User $record): string => 'Rp '.number_format($this->statsByOwner[$record->id]['omset_bulan_ini'] ?? 0, 0, ',', '.')),
            ]);
    }

    /**
     * @param  Collection<int, User>  $owners
     * @return array<int, array<string, mixed>>
     */
    private function computeStatsByOwner(Collection $owners): array
    {
        $out = [];
        foreach ($owners as $owner) {
            $out[$owner->id] = [
                'operators_count' => (int) $owner->operators_count,
                'active_kiosks_count' => (int) $owner->active_kiosks_count,
                'trip_hari_ini' => 0,
                'omset_hari_ini' => 0.0,
                'komisi_hari_ini' => 0.0,
                'untung_bersih_hari_ini' => 0.0,
                'total_trip_bulan_ini' => 0,
                'omset_bulan_ini' => 0.0,
            ];
        }

        if ($owners->isEmpty()) {
            return $out;
        }

        // SATU query: semua trip BULAN INI lintas SEMUA owner ("hari ini" adalah
        // subset-nya, difilter di PHP — hemat satu query lagi karena hari ini
        // selalu ada di dalam rentang bulan ini).
        $monthTrips = Trip::whereIn('owner_id', $owners->pluck('id'))
            ->whereMonth('trip_date', now()->month)
            ->whereYear('trip_date', now()->year)
            ->get(['id', 'owner_id', 'trip_date']);

        $allTripIds = $monthTrips->pluck('id')->all();

        // SATU pemanggilan TripAggregator untuk SEMUA trip SEMUA owner — bukan
        // per-owner, bukan per-baris. $komisiPerMika fallback 1000 hanya menyentuh
        // trip pra-snapshot yang belum di-backfill (seharusnya tak ada lagi di
        // produksi); trip ber-snapshot membaca tarifnya sendiri lewat query batch
        // internal TripAggregator (lihat method-nya).
        $agg = TripAggregator::for($allTripIds, 1000);

        $todayStr = today()->format('Y-m-d');

        foreach ($monthTrips as $trip) {
            $ownerId = $trip->owner_id;
            if (! isset($out[$ownerId])) {
                continue; // trip milik owner luar daftar (seharusnya tak terjadi — whereIn di atas).
            }

            $a = $agg[$trip->id] ?? null;
            if ($a === null) {
                continue;
            }

            $out[$ownerId]['total_trip_bulan_ini']++;
            $out[$ownerId]['omset_bulan_ini'] += $a['omset'];

            if ($trip->trip_date->format('Y-m-d') === $todayStr) {
                $out[$ownerId]['trip_hari_ini']++;
                $out[$ownerId]['omset_hari_ini'] += $a['omset'];
                $out[$ownerId]['komisi_hari_ini'] += $a['komisi'];
                $out[$ownerId]['untung_bersih_hari_ini'] += $a['untung_bersih'];
            }
        }

        return $out;
    }
}
