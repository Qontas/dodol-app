<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PriceTierResource\Pages;
use App\Models\PriceTier;
use App\Models\ProductVariant;
use App\Support\KioskPricing;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PriceTierResource extends Resource
{
    protected static ?string $model = PriceTier::class;
    protected static ?string $navigationIcon = 'heroicon-o-tag';
    protected static ?string $navigationGroup = 'Master Data';
    protected static ?string $navigationLabel = 'Tingkatan Harga';
    protected static ?string $modelLabel = 'Tingkatan Harga';
    protected static ?string $pluralModelLabel = 'Tingkatan Harga';
    protected static ?int $navigationSort = 6;

    public static function canAccess(): bool
    {
        return auth()->user()?->isOwner() ?? false;
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function canCreate(): bool
    {
        return static::canAccess();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canAccess() && (int) $record->owner_id === auth()->id();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canEdit($record) && ! $record->is_default;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('owner_id', auth()->id())->with('productVariant');
    }

    public static function variantOptions(): array
    {
        if (! static::canAccess()) {
            return [];
        }

        return ProductVariant::query()->whereHas('product', fn (Builder $query) => $query->where('owner_id', auth()->id()))
            ->with('product')->orderBy('id')->get()
            ->mapWithKeys(fn (ProductVariant $variant) => [$variant->id => $variant->product->name.' — '.$variant->name])->all();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('product_variant_id')->label('Varian Produk')
                ->options(fn () => static::variantOptions())->searchable()->required()
                ->disabled(fn (?PriceTier $record) => $record !== null)->dehydrated(),
            Forms\Components\TextInput::make('name')->label('Nama Tingkatan')->required()->maxLength(255),
            Forms\Components\TextInput::make('sale_price_per_pack')->label('Harga per Mika')
                ->prefix('Rp')->numeric()->minValue(0)->required(),
            Forms\Components\Toggle::make('is_default')->label('Harga Default')->default(false)->live()
                ->helperText('Mengaktifkan harga ini sebagai default otomatis menggantikan default varian sebelumnya. Default lama tidak dapat dilepas tanpa pengganti.')
                ->disabled(fn (?PriceTier $record) => (bool) $record?->is_default)->dehydrated(),
            Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true)
                ->disabled(fn (Get $get) => (bool) $get('is_default'))->dehydrated()
                ->helperText('Harga default harus aktif. Harga nonaktif tidak dapat dipilih kios baru; kios yang sudah memakainya tetap memakai harga tersebut.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('name')->label('Tingkatan')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('productVariant.name')->label('Varian'),
            Tables\Columns\TextColumn::make('sale_price_per_pack')->label('Harga')
                ->formatStateUsing(fn (PriceTier $record) => KioskPricing::label($record)),
            Tables\Columns\IconColumn::make('is_default')->label('Default')->boolean(),
            Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean(),
        ])->defaultSort('id')->actions([
            Tables\Actions\EditAction::make()->mutateFormDataUsing(function (array $data, PriceTier $record): array {
                $data['owner_id'] = auth()->id();
                $data['product_variant_id'] = $record->product_variant_id;

                return $data;
            }),
            Tables\Actions\DeleteAction::make()->visible(fn (PriceTier $record) => ! $record->is_default),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManagePriceTiers::route('/')];
    }
}
