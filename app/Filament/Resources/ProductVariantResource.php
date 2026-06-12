<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductVariantResource\Pages;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ProductVariantResource extends Resource
{
    protected static ?string $model = ProductVariant::class;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationLabel = 'Varian Produk';

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'Varian Produk';

    protected static ?string $pluralModelLabel = 'Varian Produk';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('product_id')
                    ->label('Produk')
                    ->relationship('product', 'name', fn ($query) => $query
                        ->where('is_active', true)
                        // Owner hanya boleh memilih produk miliknya; super admin lihat semua.
                        ->when(! (auth()->user()?->isSuperAdmin() ?? false),
                            fn ($q) => $q->where('owner_id', auth()->id())))
                    ->searchable()
                    ->preload()
                    ->required()
                    ->placeholder('Pilih produk'),

                Forms\Components\TextInput::make('name')
                    ->label('Nama Varian')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('Contoh: Mika 15 biji, Pack 20 biji'),

                Forms\Components\TextInput::make('units_per_pack')
                    ->label('Isi per Pack')
                    ->numeric()
                    ->required()
                    ->default(15)
                    ->minValue(1)
                    ->maxValue(1000)
                    ->suffix('biji')
                    ->helperText('Berapa biji dodol dalam 1 pack/mika'),

                Forms\Components\TextInput::make('sale_price_per_pack')
                    ->label('Harga Jual per Pack')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->prefix('Rp')
                    ->placeholder('12000')
                    ->helperText('Harga jual ke kios per pack/mika'),

                Forms\Components\TextInput::make('sku')
                    ->label('SKU')
                    ->maxLength(100)
                    ->placeholder('Contoh: DDL-CSU-MK15')
                    ->helperText('Optional. Kode unik untuk varian ini')
                    ->unique(ignoreRecord: true),

                Forms\Components\Toggle::make('is_active')
                    ->label('Status Aktif')
                    ->default(true)
                    ->helperText('Nonaktifkan kalau varian ini sudah tidak dijual'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('product.name')
                    ->label('Produk')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Varian')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('units_per_pack')
                    ->label('Isi')
                    ->suffix(' biji')
                    ->alignCenter()
                    ->sortable(),

                Tables\Columns\TextColumn::make('sale_price_per_pack')
                    ->label('Harga Jual')
                    ->money('IDR')
                    ->sortable(),

                Tables\Columns\TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('product.name', 'asc')
            ->filters([
                SelectFilter::make('product_id')
                    ->label('Produk')
                    ->relationship('product', 'name')
                    ->searchable()
                    ->preload()
                    ->multiple(),

                TernaryFilter::make('is_active')
                    ->label('Status')
                    ->placeholder('Semua')
                    ->trueLabel('Hanya Aktif')
                    ->falseLabel('Hanya Nonaktif'),
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

        // Varian Level 2: owner lewat product.owner_id
        return $query->whereHas('product', fn (Builder $q) => $q->where('owner_id', auth()->id()));
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductVariants::route('/'),
            'create' => Pages\CreateProductVariant::route('/create'),
            'edit' => Pages\EditProductVariant::route('/{record}/edit'),
        ];
    }
}
