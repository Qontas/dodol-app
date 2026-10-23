<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\Support\AppFeeCalculator;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * TUGAS 4e (BRIEF 23 Okt 2026) — widget admin "Fee Aplikasi", HANYA super admin
 * (fee aplikasi = uang owner partner → pemilik aplikasi, bukan urusan owner/
 * operator — lihat TUGAS 4f: tidak boleh muncul di dashboard owner, Riwayat
 * Trip, Laporan Bulanan, export, atau layar operator).
 *
 * SATU pemanggilan AppFeeCalculator::summaryForOwners() (2 query batch internal,
 * lihat docblock-nya) + 1 query daftar owner untuk table()->query() = 3 query
 * KONSTAN berapa pun banyak owner (lihat AppFeeWidgetQueryCountTest). Baris
 * TOTAL dirender lewat Summarizer bawaan Filament, closure membaca ULANG dari
 * array $summary yang SUDAH dihitung di atas (0 query tambahan) — bukan dari
 * kolom asli tabel `users` (summary bawaan Filament akan SELECT SUM() kolom
 * yang tak ada kalau dibiarkan default, makanya WAJIB ->using()).
 */
class AppFeeWidget extends BaseWidget
{
    protected static ?string $heading = 'Fee Aplikasi';

    protected int | string | array $columnSpan = 'full';

    /** @var array<int, array<string, mixed>> owner_id => metrik, dihitung SEKALI di table(). */
    private array $summary = [];

    public static function canView(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function table(Table $table): Table
    {
        $this->summary = AppFeeCalculator::summaryForOwners();

        return $table
            ->query(User::query()->where('role', 'owner'))
            ->poll('30s')
            ->columns([
                TextColumn::make('name')
                    ->label('Owner')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('rate')
                    ->label('Tarif')
                    ->state(fn (User $record): string => 'Rp '.number_format($this->summary[$record->id]['rate'] ?? 0, 0, ',', '.')),

                TextColumn::make('mika_today')
                    ->label('Mika Kena Fee (Hari Ini)')
                    ->state(fn (User $record): int => $this->summary[$record->id]['mika_today'] ?? 0)
                    ->summarize(
                        Summarizer::make()
                            ->label('Total')
                            ->using(fn (): int => array_sum(array_column($this->summary, 'mika_today')))
                    ),

                TextColumn::make('mika_month')
                    ->label('Mika Kena Fee (Bulan Ini)')
                    ->state(fn (User $record): int => $this->summary[$record->id]['mika_month'] ?? 0)
                    ->summarize(
                        Summarizer::make()
                            ->label('Total')
                            ->using(fn (): int => array_sum(array_column($this->summary, 'mika_month')))
                    ),

                TextColumn::make('mika_total')
                    ->label('Mika Kena Fee (Total)')
                    ->state(fn (User $record): int => $this->summary[$record->id]['mika_total'] ?? 0)
                    ->summarize(
                        Summarizer::make()
                            ->label('Total')
                            ->using(fn (): int => array_sum(array_column($this->summary, 'mika_total')))
                    ),

                TextColumn::make('fee_today')
                    ->label('Fee Rp (Hari Ini)')
                    ->state(fn (User $record): string => 'Rp '.number_format($this->summary[$record->id]['fee_today'] ?? 0, 0, ',', '.'))
                    ->summarize(
                        Summarizer::make()
                            ->label('Total')
                            ->using(fn (): string => 'Rp '.number_format(array_sum(array_column($this->summary, 'fee_today')), 0, ',', '.'))
                    ),

                TextColumn::make('fee_month')
                    ->label('Fee Rp (Bulan Ini)')
                    ->state(fn (User $record): string => 'Rp '.number_format($this->summary[$record->id]['fee_month'] ?? 0, 0, ',', '.'))
                    ->summarize(
                        Summarizer::make()
                            ->label('Total')
                            ->using(fn (): string => 'Rp '.number_format(array_sum(array_column($this->summary, 'fee_month')), 0, ',', '.'))
                    ),

                TextColumn::make('fee_total')
                    ->label('Fee Rp (Total)')
                    ->state(fn (User $record): string => 'Rp '.number_format($this->summary[$record->id]['fee_total'] ?? 0, 0, ',', '.'))
                    ->summarize(
                        Summarizer::make()
                            ->label('Total')
                            ->using(fn (): string => 'Rp '.number_format(array_sum(array_column($this->summary, 'fee_total')), 0, ',', '.'))
                    ),
            ]);
    }
}
