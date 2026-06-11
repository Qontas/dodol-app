<?php

namespace App\Filament\Resources;

use App\Filament\Resources\KioskResource\Pages;
use App\Models\Kiosk;
use Illuminate\Database\Eloquent\Builder;
use Dotswan\MapPicker\Fields\Map;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class KioskResource extends Resource
{
    protected static ?string $model = Kiosk::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'Kios';

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'Kios';

    protected static ?string $pluralModelLabel = 'Kios';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Informasi Dasar')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nama Kios')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('Contoh: Kedai Bunda, Toko Doni')
                            ->columnSpan(2),

                        Forms\Components\Select::make('cluster_id')
                            ->label('Area')
                            ->relationship('cluster', 'name', fn($query) => $query->where('is_active', true))
                            ->searchable()
                            ->preload()
                            ->required()
                            ->placeholder('Pilih area')
                            ->helperText('Area tempat kios ini berada')
                            ->createOptionForm([
                                Forms\Components\TextInput::make('name')
                                    ->label('Nama Area')
                                    ->required()
                                    ->maxLength(100),
                                Forms\Components\Textarea::make('notes')
                                    ->label('Deskripsi')
                                    ->rows(2),
                            ]),

                        Forms\Components\TextInput::make('owner_name')
                            ->label('Nama Pemilik')
                            ->maxLength(255)
                            ->placeholder('Contoh: Pak Rizki, Bu Sri'),

                        Forms\Components\TextInput::make('phone')
                            ->label('Nomor Telepon')
                            ->tel()
                            ->maxLength(20)
                            ->placeholder('+62 812 3456 7890')
                            ->helperText('Untuk WhatsApp atau telepon kios'),

                        Forms\Components\DatePicker::make('first_titip_date')
                            ->label('Tanggal Pertama Titip')
                            ->displayFormat('d M Y')
                            ->helperText('Optional. Untuk hitung umur kios sebagai customer. Kosongkan untuk auto-set saat delivery pertama.'),
                    ]),

                Section::make('Lokasi')
                    ->columns(2)
                    ->collapsible()
                    ->schema([
                        Forms\Components\Textarea::make('location_description')
                            ->label('Alamat Lengkap')
                            ->rows(3)
                            ->maxLength(500)
                            ->placeholder('Contoh: Jl. Persatuan No. 33, Medan')
                            ->columnSpan(2),

                        Map::make('location')
                            ->label('Lokasi Kios (klik titik di peta)')
                            ->columnSpanFull()
                            ->defaultLocation(latitude: 3.5952, longitude: 98.6722)
                            ->draggable()
                            ->clickable(true)
                            ->afterStateUpdated(function (Set $set, ?array $state): void {
                                $set('latitude', $state['lat'] ?? null);
                                $set('longitude', $state['lng'] ?? null);
                            })
                            ->afterStateHydrated(function ($state, $record, Set $set): void {
                                $set('location', [
                                    'lat' => $record?->latitude ?? 3.5952,
                                    'lng' => $record?->longitude ?? 98.6722,
                                ]);
                            })
                            ->liveLocation()
                            ->showMarker()
                            ->markerColor('#FBBF24')
                            ->showFullscreenControl()
                            ->showZoomControl()
                            ->tilesUrl('https://tile.openstreetmap.org/{z}/{x}/{y}.png')
                            ->zoom(13)
                            ->detectRetina()
                            ->showMyLocationButton()
                            ->extraTileControl([])
                            ->extraControl(['zoomDelta' => 1, 'zoomSnap' => 2])
                            ->dehydrated(false),

                        // Kolom asli tetap disimpan ke DB lewat hidden field
                        Forms\Components\Hidden::make('latitude'),
                        Forms\Components\Hidden::make('longitude'),
                    ]),

                Section::make('Foto & Konfigurasi')
                    ->columns(2)
                    ->collapsible()
                    ->schema([
                        Forms\Components\FileUpload::make('photo_path')
                            ->label('Foto Kios')
                            ->image()
                            ->imageEditor()
                            ->maxSize(2048)
                            ->disk('public')
                            ->directory('kiosks')
                            ->visibility('public')
                            ->helperText('Maksimal 2MB. Format: JPG, PNG. Optional.')
                            ->columnSpan(2),

                        Forms\Components\TextInput::make('warning_visit_interval_days')
                            ->label('Threshold Warning (hari)')
                            ->numeric()
                            ->default(10)
                            ->minValue(1)
                            ->maxValue(60)
                            ->suffix('hari')
                            ->helperText('Kios akan ditandai warning kalau melewati threshold ini'),

                        Forms\Components\TextInput::make('target_visit_interval_days')
                            ->label('Target Interval Kunjungan (hari)')
                            ->numeric()
                            ->default(14)
                            ->minValue(1)
                            ->maxValue(90)
                            ->suffix('hari')
                            ->helperText('Berapa hari sekali kios ini harus dikunjungi'),

                        Forms\Components\TextInput::make('fast_mover_threshold_days')
                            ->label('Threshold Fast Mover (hari)')
                            ->numeric()
                            ->nullable()
                            ->minValue(1)
                            ->maxValue(120)
                            ->suffix('hari')
                            ->helperText('Rata-rata habis < X hari = Fast Mover. Kosongkan = tidak dimonitor.')
                            ->placeholder('Contoh: 5'),

                        Forms\Components\TextInput::make('default_qty_mika')
                            ->label('Default Qty Mika per Antar')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(100)
                            ->suffix('mika')
                            ->helperText('Optional. Berapa mika default tiap antar (kosongkan kalau variabel)'),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Status Aktif')
                            ->default(true)
                            ->helperText('Nonaktifkan kalau kios ini stop/tutup'),

                        Forms\Components\Toggle::make('is_cash_only')
                            ->label('Kios Cash Only')
                            ->default(false)
                            ->helperText('Aktifkan jika kios ini selalu bayar cash langsung (tidak ada konsinyasi)'),
                    ]),

                Section::make('Catatan')
                    ->collapsed()
                    ->schema([
                        Forms\Components\Textarea::make('notes')
                            ->label('Catatan')
                            ->rows(3)
                            ->maxLength(1000)
                            ->placeholder('Catatan tambahan tentang kios ini')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('photo_path')
                    ->label('')
                    ->disk('public')
                    ->square()
                    ->size(50),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Kios')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn($record) => $record->owner_name),

                Tables\Columns\TextColumn::make('cluster.name')
                    ->label('Area')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Telepon')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('location_description')
                    ->label('Alamat')
                    ->limit(50)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_cash_only')
                    ->label('Cash Only')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('target_visit_interval_days')
                    ->label('Interval')
                    ->suffix(' hari')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('fast_mover_threshold_days')
                    ->label('Fast Mover')
                    ->suffix(' hari')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('maps_link')
                    ->label('GPS')
                    ->getStateUsing(fn($record) => $record->maps_url ? 'Maps' : '—')
                    ->url(fn($record) => $record->maps_url)
                    ->openUrlInNewTab()
                    ->icon(fn($record) => $record->maps_url ? 'heroicon-o-map-pin' : null)
                    ->color(fn($record) => $record->maps_url ? 'success' : 'gray')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('first_titip_date')
                    ->label('Pertama Titip')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name', 'asc')
            ->filters([
                SelectFilter::make('cluster_id')
                    ->label('Area')
                    ->relationship('cluster', 'name')
                    ->searchable()
                    ->preload()
                    ->multiple(),

                TernaryFilter::make('is_active')
                    ->label('Status')
                    ->placeholder('Semua')
                    ->trueLabel('Hanya Aktif')
                    ->falseLabel('Hanya Nonaktif'),

                TernaryFilter::make('has_gps')
                    ->label('GPS')
                    ->placeholder('Semua')
                    ->trueLabel('Ada GPS')
                    ->falseLabel('Belum ada GPS')
                    ->queries(
                        true: fn($query) => $query->whereNotNull('latitude')->whereNotNull('longitude'),
                        false: fn($query) => $query->whereNull('latitude')->orWhereNull('longitude'),
                    ),
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

        // Kios Level 2: owner lewat cluster.owner_id
        return $query->whereHas('cluster', fn (Builder $q) => $q->where('owner_id', auth()->id()));
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListKiosks::route('/'),
            'create' => Pages\CreateKiosk::route('/create'),
            'edit' => Pages\EditKiosk::route('/{record}/edit'),
        ];
    }
}
