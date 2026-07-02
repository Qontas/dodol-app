<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProcurementBatchResource\Pages;
use App\Models\ProcurementBatch;
use Illuminate\Database\Eloquent\Builder;
use Filament\Forms;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProcurementBatchResource extends Resource
{
    protected static ?string $model = ProcurementBatch::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationLabel = 'Pengadaan';

    protected static ?string $navigationGroup = 'Transaksi';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Pengadaan';

    protected static ?string $pluralmodelLabel = 'Pengadaan';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Supplier & Produk')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('supplier_id')
                            ->label('Supplier')
                            // 🔒 Owner hanya boleh pilih supplier miliknya; super admin lihat semua.
                            ->relationship('supplier', 'name', fn ($query) => $query
                                ->where('is_active', true)
                                ->when(! (auth()->user()?->isSuperAdmin() ?? false),
                                    fn ($q) => $q->where('owner_id', auth()->id())))
                            ->searchable()
                            ->preload()
                            ->required()
                            ->placeholder('Pilih supplier')
                            ->helperText('Supplier asal pembelian'),

                        Forms\Components\Select::make('product_variant_id')
                            ->label('Varian Produk')
                            // 🔒 Varian Level 2: owner lewat product.owner_id — hanya varian milik owner.
                            ->relationship('productVariant', 'name', fn ($query) => $query
                                ->where('is_active', true)
                                ->when(! (auth()->user()?->isSuperAdmin() ?? false),
                                    fn ($q) => $q->whereHas('product', fn ($p) => $p->where('owner_id', auth()->id()))))
                            ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->product->name} - {$record->name}")
                            ->searchable()
                            ->preload()
                            ->required()
                            ->placeholder('Pilih varian produk')
                            ->helperText('Varian dodol yang dibeli'),
                    ]),

                Section::make('Detail Pembelian')
                    ->columns(2)
                    ->schema([
                        Forms\Components\DatePicker::make('purchase_date')
                            ->label('Tanggal Beli')
                            ->required()
                            ->default(now())
                            ->displayFormat('d M Y')
                            ->maxDate(now()),

                        Forms\Components\DatePicker::make('expiry_date')
                            ->label('Tanggal Kadaluarsa')
                            ->required()
                            ->displayFormat('d M Y')
                            ->after('purchase_date')
                            ->helperText('Tanggal kadaluarsa dodol dalam batch ini'),
                    ]),

                Section::make('Jumlah (Hasil Repackaging)')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('qty_kg_raw')
                            ->label('Qty Pembelian Mentah')
                            ->required()
                            ->numeric()
                            ->minValue(0.1)
                            ->step(0.01)
                            ->suffix('kg')
                            ->placeholder('20')
                            ->helperText('Berapa kg dodol mentah dibeli dari supplier'),

                        Forms\Components\TextInput::make('qty_packs')
                            ->label('Qty Mika Jadi (Actual)')
                            ->required()
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(10000)
                            ->suffix('mika')
                            ->placeholder('72')
                            ->helperText('Jumlah mika ACTUAL setelah repackaging, sudah dikurangi mika rusak/penyet. Bukan estimate!'),
                    ]),

                Section::make('Biaya')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('cost_raw_material')
                            ->label('Biaya Dodol Mentah')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->prefix('Rp')
                            ->placeholder('520000')
                            ->helperText('Harga beli dodol mentah dari supplier'),

                        Forms\Components\TextInput::make('cost_packing')
                            ->label('Biaya Repackaging')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->prefix('Rp')
                            ->placeholder('15000')
                            ->helperText('Optional. Biaya proses repackaging (tenaga kerja, listrik, dll)'),

                        Forms\Components\TextInput::make('cost_packaging')
                            ->label('Biaya Kemasan')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->prefix('Rp')
                            ->placeholder('14400')
                            ->helperText('Optional. Biaya material mika + plastik + label'),

                        Forms\Components\TextInput::make('cost_other')
                            ->label('Biaya Lain-Lain')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->prefix('Rp')
                            ->placeholder('30000')
                            ->helperText('Optional. Biaya transport, parkir, dll'),
                    ]),

                Section::make('Auto-Calculated (Read-Only Preview)')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Placeholder::make('batch_number_preview')
                            ->label('Nomor Batch (Auto)')
                            ->content(fn ($record) => $record?->batch_number ?? 'Akan dibuat saat save')
                            ->helperText('Auto-generated format: BATCH-YYYY-MM-DD-NNN'),

                        Placeholder::make('total_cost_preview')
                            ->label('Total Biaya (Otomatis)')
                            ->content(fn (Get $get) => 'Rp '.number_format(
                                (float) ($get('cost_raw_material') ?? 0)
                                + (float) ($get('cost_packing') ?? 0)
                                + (float) ($get('cost_packaging') ?? 0)
                                + (float) ($get('cost_other') ?? 0),
                                0, ',', '.'
                            ))
                            ->helperText('Total biaya = dodol + repackaging + kemasan + lain-lain'),

                        Placeholder::make('cost_per_pack_preview')
                            ->label('HPP per Mika (Auto)')
                            ->content(function (Get $get) {
                                $total = (float) ($get('cost_raw_material') ?? 0)
                                    + (float) ($get('cost_packing') ?? 0)
                                    + (float) ($get('cost_packaging') ?? 0)
                                    + (float) ($get('cost_other') ?? 0);
                                $packs = (int) ($get('qty_packs') ?? 0);
                                if ($packs === 0) {
                                    return 'Rp 0 (input qty_packs dulu)';
                                }

                                return 'Rp '.number_format($total / $packs, 0, ',', '.').' / mika';
                            })
                            ->helperText('HPP per mika = total_cost / qty_packs'),

                        Placeholder::make('price_per_kg_raw_preview')
                            ->label('Harga per Kg Mentah (Auto)')
                            ->content(function (Get $get) {
                                $cost = (float) ($get('cost_raw_material') ?? 0);
                                $kg = (float) ($get('qty_kg_raw') ?? 0);
                                if ($kg === 0.0) {
                                    return 'Rp 0 (input qty_kg dulu)';
                                }

                                return 'Rp '.number_format($cost / $kg, 0, ',', '.').' / kg';
                            }),
                    ]),

                Section::make('Catatan')
                    ->collapsed()
                    ->schema([
                        Forms\Components\Textarea::make('notes')
                            ->label('Catatan')
                            ->rows(3)
                            ->maxLength(500)
                            ->placeholder('Contoh: 20kg dapat 70 mika, 2 mika penyet, 3 mika rusak')
                            ->helperText('Catatan kondisi batch, loss repackaging, dll')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('batch_number')
                    ->label('Batch')
                    ->getStateUsing(fn ($record) => $record->batch_number)
                    ->sortable(query: fn ($query, string $direction) => $query->orderBy('created_at', $direction))
                    ->weight('bold')
                    ->copyable(),

                Tables\Columns\TextColumn::make('purchase_date')
                    ->label('Tanggal Beli')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('supplier.name')
                    ->label('Supplier')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('productVariant.name')
                    ->label('Varian')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn ($record) => "{$record->productVariant->product->name} - {$record->productVariant->name}")
                    ->toggleable(),

                Tables\Columns\TextColumn::make('qty_kg_raw')
                    ->label('Pembelian')
                    ->suffix(' kg')
                    ->alignRight()
                    ->sortable(),

                Tables\Columns\TextColumn::make('qty_packs')
                    ->label('Mika Jadi')
                    ->suffix(' mika')
                    ->alignRight()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('stok_tersisa')
                    ->label('Stok Tersisa')
                    ->getStateUsing(fn ($record) => $record->stok_tersisa.' mika')
                    ->badge()
                    ->color(fn ($record) => match (true) {
                        $record->is_habis => 'danger',
                        $record->is_hampis_habis => 'warning',
                        default => 'success',
                    })
                    ->sortable(false)
                    ->summarize(
                        Summarizer::make()
                            ->label('Total sisa')
                            // Hitung di SQL: per batch GREATEST(qty_packs - sum(qty_delivered), 0), lalu dijumlah.
                            ->using(fn ($query): string => (int) $query->sum(\Illuminate\Support\Facades\DB::raw(
                                'GREATEST(qty_packs - COALESCE((SELECT SUM(d.qty_delivered) FROM deliveries d '
                                .'WHERE d.procurement_batch_id = procurement_batches.id), 0), 0)'
                            )).' mika')
                    ),

                Tables\Columns\TextColumn::make('total_cost')
                    ->label('Total Biaya')
                    ->money('IDR')
                    ->sortable(),

                Tables\Columns\TextColumn::make('cost_per_pack')
                    ->label('HPP/Mika')
                    ->money('IDR')
                    ->sortable()
                    ->weight('bold')
                    ->color('success'),

                Tables\Columns\TextColumn::make('expiry_date')
                    ->label('Kadaluarsa')
                    ->date('d M Y')
                    ->sortable()
                    ->color(fn ($record) => $record->expiry_date < now()
                        ? 'danger'
                        : ($record->expiry_date < now()->addDays(7) ? 'warning' : 'success'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('purchase_date', 'desc')
            ->filters([
                SelectFilter::make('supplier_id')
                    ->label('Supplier')
                    // 🔒 Filter di-scope owner.
                    ->relationship('supplier', 'name', fn ($query) => $query
                        ->when(! (auth()->user()?->isSuperAdmin() ?? false),
                            fn ($q) => $q->where('owner_id', auth()->id())))
                    ->searchable()
                    ->preload()
                    ->multiple(),

                SelectFilter::make('product_variant_id')
                    ->label('Varian Produk')
                    // 🔒 Filter di-scope owner (varian lewat product.owner_id).
                    ->relationship('productVariant', 'name', fn ($query) => $query
                        ->when(! (auth()->user()?->isSuperAdmin() ?? false),
                            fn ($q) => $q->whereHas('product', fn ($p) => $p->where('owner_id', auth()->id()))))
                    ->searchable()
                    ->preload()
                    ->multiple(),

                Filter::make('expired')
                    ->label('Status Expiry')
                    ->form([
                        Forms\Components\Select::make('expiry_status')
                            ->label('Status')
                            ->options([
                                'expired' => 'Sudah Expired',
                                'expiring_soon' => 'Expire < 7 Hari',
                                'valid' => 'Masih Valid',
                            ]),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['expiry_status'] === 'expired',
                                fn ($q) => $q->where('expiry_date', '<', now()))
                            ->when($data['expiry_status'] === 'expiring_soon',
                                fn ($q) => $q->whereBetween('expiry_date', [now(), now()->addDays(7)]))
                            ->when($data['expiry_status'] === 'valid',
                                fn ($q) => $q->where('expiry_date', '>=', now()->addDays(7)));
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->requiresConfirmation(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->requiresConfirmation(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (auth()->user()?->isSuperAdmin()) {
            return $query;
        }

        return $query->where('owner_id', auth()->id());
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProcurementBatches::route('/'),
            'create' => Pages\CreateProcurementBatch::route('/create'),
            'edit' => Pages\EditProcurementBatch::route('/{record}/edit'),
        ];
    }
}
