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
                            ->columnSpanFull(),

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
                            ->columnSpanFull(),

                        Map::make('location')
                            ->label('Lokasi Kios')
                            ->helperText('Klik titik di peta')
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
                            // Tile CARTO Voyager (gratis, CDN + subdomain {s}=abcd → tile keisi
                            // lebih cepat & kebijakan usage lebih longgar dari tile.openstreetmap.org
                            // yang rawan throttle/400). Native 256px (tileSize 256 + zoomOffset 0).
                            ->tilesUrl('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}.png')
                            ->zoom(13)
                            // AKAR "grey saat zoom KUAT": maxZoom default paket = 28, padahal tile
                            // cuma ada s/d ~z20. Zoom lewat itu → provider balas 400/tanpa tile →
                            // GREY. Cap maxZoom = 20 (map & tileLayer) supaya user tak bisa over-zoom
                            // ke zona tanpa-tile. z20 = level bangunan, cukup buat nunjuk lokasi kios.
                            ->maxZoom(20)
                            // detectRetina default paket = true; matikan eksplisit supaya
                            // mobile retina tidak minta tile @2x (memperparah grey saat zoom).
                            ->detectRetina(false)
                            ->showMyLocationButton()
                            // zoomSnap 1 (default Leaflet) — sebelumnya zoomSnap 2 bikin
                            // langkah zoom tak lazim & memicu tile kosong saat pinch di mobile.
                            ->extraControl(['zoomSnap' => 1, 'zoomDelta' => 1])
                            // tileLayer: 256px native + cap maxZoom 20 (samakan dgn map) + atribusi.
                            ->extraTileControl([
                                'tileSize' => 256,
                                'zoomOffset' => 0,
                                'maxZoom' => 20,
                                'attribution' => '© OpenStreetMap, © CARTO',
                            ])
                            ->dehydrated(false),

                        // Paksa Leaflet hitung ulang ukuran container setelah layout mobile
                        // settle / section Lokasi dibuka → hilangkan peta abu-abu (grey tiles).
                        Forms\Components\View::make('filament.forms.map-invalidate-size')
                            ->columnSpanFull(),

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
                            // Kompres di browser sebelum upload (Filepond): perkecil ke
                            // sisi maks 1280px supaya hemat & cepat, selaras form operator.
                            ->imageResizeMode('contain')
                            ->imageResizeTargetWidth('1280')
                            ->imageResizeTargetHeight('1280')
                            ->maxSize(5120)
                            ->disk(config('app.media_disk', 'public'))
                            ->directory('kiosks')
                            ->visibility('public')
                            ->helperText('Foto akan dikecilkan otomatis. Maksimal 5MB. Format: JPG, PNG, WEBP. Opsional.')
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('warning_visit_interval_days')
                            ->label('Peringatan kalau belum dikunjungi (hari)')
                            ->numeric()
                            ->default(10)
                            ->minValue(1)
                            ->maxValue(60)
                            ->suffix('hari')
                            ->helperText('Kios ditandai peringatan kalau lewat sekian hari belum dikunjungi.'),

                        Forms\Components\TextInput::make('target_visit_interval_days')
                            ->label('Idealnya dikunjungi tiap berapa hari (hari)')
                            ->numeric()
                            ->default(14)
                            ->minValue(1)
                            ->maxValue(90)
                            ->suffix('hari')
                            ->helperText('Berapa hari sekali kios ini harus dikunjungi'),

                        Forms\Components\TextInput::make('fast_mover_threshold_days')
                            ->label('Batas kios laris (hari)')
                            ->numeric()
                            ->nullable()
                            ->minValue(1)
                            ->maxValue(120)
                            ->suffix('hari')
                            ->helperText('Kalau dodol biasanya habis di bawah sekian hari, kios ini dianggap laris. Kosongkan kalau tidak dipantau.')
                            ->placeholder('Contoh: 5'),

                        Forms\Components\TextInput::make('default_qty_mika')
                            ->label('Jumlah Mika Biasanya per Antar')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(100)
                            ->suffix('mika')
                            ->helperText('Berapa mika yang biasa dititip tiap pengantaran (kosongkan kalau berubah-ubah).'),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Status Aktif')
                            ->default(true)
                            ->helperText('Nonaktifkan kalau kios ini stop/tutup'),

                        Forms\Components\Toggle::make('is_cash_only')
                            ->label('Kios Bayar Tunai Langsung')
                            ->default(false)
                            ->helperText('Aktifkan kalau kios ini selalu bayar tunai saat itu juga, tidak menitip dulu.'),
                    ]),

                Section::make('Riwayat Stop Titipan')
                    ->description('Kios ini sedang dihentikan titipannya.')
                    ->columns(2)
                    ->visible(fn (?Kiosk $record): bool => $record !== null && ! $record->is_active && $record->stopped_at !== null)
                    ->schema([
                        Forms\Components\Placeholder::make('stopped_at_display')
                            ->label('Tanggal Stop')
                            ->content(fn (?Kiosk $record): string => $record?->stopped_at?->translatedFormat('d M Y H:i') ?? '—'),

                        Forms\Components\Placeholder::make('stop_reason_display')
                            ->label('Alasan')
                            ->content(fn (?Kiosk $record): string => $record?->stop_reason_label ?? '—'),

                        Forms\Components\Placeholder::make('stopped_by_display')
                            ->label('Dihentikan Oleh')
                            ->content(fn (?Kiosk $record): string => $record?->stopped_by ? ucfirst($record->stopped_by) : '—'),
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
                    ->disk(config('app.media_disk', 'public'))
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

                Tables\Columns\TextColumn::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Aktif' : 'Stop')
                    ->color(fn (bool $state): string => $state ? 'success' : 'danger')
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_cash_only')
                    ->label('Tunai')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('target_visit_interval_days')
                    ->label('Jadwal')
                    ->suffix(' hari')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('fast_mover_threshold_days')
                    ->label('Laris')
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
                    ->trueLabel('Aktif')
                    ->falseLabel('Stop'),

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
                Tables\Actions\Action::make('stop')
                    ->label('Stop Titipan')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (Kiosk $record): bool => $record->is_active)
                    ->requiresConfirmation()
                    ->modalHeading('Stop Titipan Kios')
                    ->modalDescription('Kios akan dihentikan titipannya dan tidak muncul di daftar kunjungan trip. Data historis tetap ada.')
                    ->form([
                        Forms\Components\Select::make('stop_reason')
                            ->label('Alasan Stop')
                            ->options(Kiosk::STOP_REASONS)
                            ->required(),
                    ])
                    ->action(function (Kiosk $record, array $data): void {
                        $record->update([
                            'is_active' => false,
                            'stopped_at' => now(),
                            'stop_reason' => $data['stop_reason'],
                            'stopped_by' => 'owner',
                        ]);
                    }),

                Tables\Actions\Action::make('reactivate')
                    ->label('Aktifkan Kembali')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->visible(fn (Kiosk $record): bool => ! $record->is_active)
                    ->requiresConfirmation()
                    ->modalHeading('Aktifkan Kembali Kios')
                    ->modalDescription('Kios akan kembali muncul di daftar kunjungan trip.')
                    ->action(function (Kiosk $record): void {
                        $record->update([
                            'is_active' => true,
                            'stopped_at' => null,
                            'stop_reason' => null,
                            'stopped_by' => null,
                        ]);
                    }),

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

        // Kios sentinel walk-in tidak pernah ditampilkan/diedit di panel — bahkan untuk super admin.
        $query->excludeWalkInSentinel();

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
