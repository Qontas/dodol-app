<?php

namespace App\Filament\Resources;

use App\Filament\Resources\KioskResource\Pages;
use App\Models\Cluster;
use App\Models\Kiosk;
use App\Support\GoogleMapsShortLinkResolver;
use App\Support\KioskLocationParser;
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Forms\Components\LeafletMapPicker;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
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

    // Placeholder ikon foto (SVG inline, bukan file baru) utk kios yang photo_path-nya
    // NULL (belum pernah difoto — data import lama, dsb). Beda dari kasus "foto ada tapi
    // gagal tampil" di atas: ini memang tak punya foto sama sekali, jadi kotak kosong
    // diganti ikon rapi, bukan menyembunyikan masalah.
    private const PHOTO_PLACEHOLDER = 'data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'%239ca3af\' stroke-width=\'1.5\'%3E%3Crect x=\'3\' y=\'3\' width=\'18\' height=\'18\' rx=\'2\' ry=\'2\'/%3E%3Ccircle cx=\'8.5\' cy=\'8.5\' r=\'1.5\'/%3E%3Cpolyline points=\'21 15 16 10 5 21\'/%3E%3C/svg%3E';

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
                            ->relationship('cluster', 'name', fn ($query) => $query
                                ->where('is_active', true)
                                // 🔒 Owner hanya boleh pilih area MILIKNYA; super admin lihat semua.
                                // Tanpa ini dropdown bocor cluster owner lain → kios ke-assign ke
                                // cluster owner lain → hilang dari list owner (di-filter cluster.owner_id)
                                // + bocor multi-tenant.
                                ->when(! (auth()->user()?->isSuperAdmin() ?? false),
                                    fn ($q) => $q->where('owner_id', auth()->id())))
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

                        Forms\Components\Textarea::make('store_note')
                            ->label('Catatan Kedai')
                            ->rows(2)
                            ->maxLength(500)
                            ->placeholder('cth: Cash-only, biasa minta 5 mika / Suka-suka, kadang banyak kadang dikit')
                            ->helperText('Karakteristik/kebiasaan kedai. Tampil MENONJOL ke operator saat buka kedai.')
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('sort_order')
                            ->label('Urutan Rute (dalam Area)')
                            ->numeric()
                            ->placeholder('Kosongkan = di bawah dalam area ini')
                            ->helperText('Angka lebih kecil tampil lebih dulu DALAM AREA yang sama, di daftar & urutan kunjungan operator. Isi angka yang sudah dipakai kios lain? Otomatis digeser, tak akan kembar. Bisa juga diatur lewat drag di halaman daftar — filter ke satu Area dulu, tombol drag baru muncul.'),

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

                // JENIS KEDAI + JATAH TITIPAN (owner input kedai KARENA sudah titip di sana).
                // SATU field mika saja: "Jatah Titipan" (default_qty_mika). Konsinyasi →
                // CreateKiosk::afterCreate() otomatis bikin 1 delivery konsinyasi PENDING
                // (App\Support\OpeningBalance) SEJUMLAH JATAH itu → kios langsung bisa "Tagih
                // + Titip Ulang" di kunjungan pertama; tagihan dikoreksi otomatis oleh input
                // sisa/BS operator di lapangan (owner tak perlu tahu stok fisik). Cash-only →
                // is_cash_only, tak ada titipan/jatah. Radio form-only (dehydrated false).
                Section::make('Jenis Kedai & Jatah Titipan')
                    ->description('Konsinyasi: dodol dititip, dibayar pas laku. Cash-only: beli putus tiap kali (tak ada titipan/jatah). Jatah titipan sekaligus jadi titipan awal — operator yang cek sisa fisiknya di lapangan.')
                    // Tampil saat create (semua jenis) ATAU saat edit kedai konsinyasi
                    // (bukan cash-only). Edit cash-only → section disembunyikan (tak relevan).
                    ->visible(fn (string $operation, Get $get): bool => $operation === 'create' || ! ($get('is_cash_only') ?? false))
                    ->schema([
                        Forms\Components\Radio::make('jenis_kedai')
                            ->label('Jenis Kedai')
                            ->options([
                                'konsinyasi' => '🏪 Kedai Konsinyasi (titipan)',
                                'cash_only' => '💵 Kedai Cash-Only (beli putus)',
                            ])
                            ->default('konsinyasi')
                            ->required()
                            ->live()
                            ->dehydrated(false)
                            ->visibleOn('create'),

                        Forms\Components\TextInput::make('default_qty_mika')
                            ->label('Jatah Titipan (mika per kunjungan)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(100)
                            ->suffix('mika')
                            ->helperText('Berapa mika yang dititip di kedai ini tiap kunjungan. Ini juga jadi titipan awal — operator yang nanti cek berapa sisanya di lapangan.')
                            // Create: hanya untuk konsinyasi. Edit: sembunyi kalau cash-only.
                            ->visible(fn (string $operation, Get $get): bool => $operation === 'create'
                                ? (($get('jenis_kedai') ?? 'konsinyasi') === 'konsinyasi')
                                : ! ($get('is_cash_only') ?? false))
                            ->required(fn (string $operation, Get $get): bool => $operation === 'create'
                                && (($get('jenis_kedai') ?? 'konsinyasi') === 'konsinyasi')),
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

                        Forms\Components\TextInput::make('maps_paste_input')
                            ->label('Tempel Link / Koordinat Google Maps')
                            ->helperText('Cari lokasi kios di Google Maps dulu (ada patokan/nama toko), lalu tempel link atau koordinatnya di sini.')
                            ->placeholder('Contoh: 3.5896, 98.6739 atau link Google Maps')
                            ->dehydrated(false)
                            ->columnSpanFull()
                            ->suffixAction(
                                Forms\Components\Actions\Action::make('jumpToMapsLocation')
                                    ->icon('heroicon-o-arrow-top-right-on-square')
                                    ->tooltip('Loncat ke lokasi')
                                    // Tier 1 (koordinat/link panjang) = parsing murni, instan. Tier 2
                                    // (link pendek maps.app.goo.gl/goo.gl) = best-effort via redirect
                                    // resolve — lihat GoogleMapsShortLinkResolver utk safeguard SSRF-nya.
                                    ->action(function (Set $set, Get $get, $livewire): void {
                                        $input = trim((string) $get('maps_paste_input'));

                                        if ($input === '') {
                                            Notification::make()
                                                ->title('Tempel link atau koordinat dulu.')
                                                ->warning()
                                                ->send();
                                            return;
                                        }

                                        $coords = KioskLocationParser::parse($input);

                                        if ($coords === null && GoogleMapsShortLinkResolver::isEligible($input)) {
                                            $resolved = GoogleMapsShortLinkResolver::resolve($input);
                                            $coords = $resolved !== null ? KioskLocationParser::parse($resolved) : null;

                                            if ($coords === null) {
                                                Notification::make()
                                                    ->title('Link pendek tidak bisa dibaca')
                                                    ->body('Buka dulu link-nya di browser, lalu tempel link panjang atau koordinatnya di sini.')
                                                    ->danger()
                                                    ->send();
                                                return;
                                            }
                                        }

                                        if ($coords === null) {
                                            Notification::make()
                                                ->title('Format tidak dikenali')
                                                ->body('Coba tempel koordinat langsung, contoh: 3.5896, 98.6739')
                                                ->danger()
                                                ->send();
                                            return;
                                        }

                                        $set('latitude', $coords['lat']);
                                        $set('longitude', $coords['lng']);
                                        $set('location', ['lat' => $coords['lat'], 'lng' => $coords['lng']]);

                                        // Leaflet hand-rolled (resources/js/... public/js/leaflet-map-picker.js):
                                        // listener event ini langsung panggil map.setView() ke koordinat
                                        // yang baru saja di-$set — tanpa perantara refreshMap/IntersectionObserver.
                                        $livewire->dispatch('kiosk-location-jumped');

                                        Notification::make()
                                            ->title('Lokasi ditemukan!')
                                            ->success()
                                            ->send();
                                    })
                            ),

                        LeafletMapPicker::make('location')
                            ->label('Lokasi Kios')
                            ->helperText('Klik titik di peta')
                            ->columnSpanFull()
                            ->defaultLocation(latitude: 3.5952, longitude: 98.6722)
                            ->draggable()
                            ->clickable()
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
                            ->markerColor('#FBBF24')
                            ->showFullscreenControl()
                            ->showZoomControl()
                            ->showMyLocationButton()
                            // Tile CARTO Voyager (gratis, CDN + subdomain {s}=abcd → tile keisi
                            // lebih cepat & kebijakan usage lebih longgar dari tile.openstreetmap.org
                            // yang rawan throttle/400). Native 256px — sudah default Leaflet, sama
                            // seperti peta operator (create-kiosk.blade.php), jadi tak perlu override
                            // tileSize/zoomOffset lagi (itu cuma perlu utk nyamain default dotswan yg beda).
                            ->tilesUrl('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}.png')
                            ->attribution('© OpenStreetMap, © CARTO')
                            ->zoom(13)
                            // AKAR "grey saat zoom KUAT": provider cuma ada tile s/d ~z20. Cap
                            // maxZoom = 20 (map & tileLayer maxNativeZoom) — user tak bisa over-zoom
                            // ke zona tanpa-tile. z20 = level bangunan, cukup buat nunjuk lokasi kios.
                            ->maxZoom(20)
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
                            // Kompres di browser sebelum upload (Filepond): perkecil ke
                            // sisi maks 1280px supaya hemat & cepat, selaras form operator.
                            ->imageResizeMode('contain')
                            ->imageResizeTargetWidth('1280')
                            ->imageResizeTargetHeight('1280')
                            ->maxSize(5120)
                            ->disk(config('app.media_disk', 'public'))
                            ->directory('kiosks')
                            ->visibility('public')
                            // AKAR yang sama dgn list owner (net::ERR_CERT_COMMON_NAME_INVALID
                            // transien ke r2.dev): default Filament (BaseFileUpload::
                            // getUploadedFileUsing bawaan) bikin URL R2 LANGSUNG utk preview
                            // FOTO YANG SUDAH TERSIMPAN saat form edit dibuka — celah ketiga yang
                            // KELEWAT saat migrasi ke proxy (list & operator sudah benar, form ini
                            // belum). Gejalanya: widget "Foto Kios" macet selamanya di "Waiting
                            // for size" krn request browser ke r2.dev gagal cert & tak pernah
                            // retry. Fix: pakai proxy same-origin yang sama (Kiosk::photo_url) —
                            // SEKARANG BENAR-BENAR satu jalur di list, operator, DAN form ini.
                            ->getUploadedFileUsing(function (Forms\Components\BaseFileUpload $component, string $file): ?array {
                                $record = $component->getRecord();
                                $url = ($record instanceof Kiosk) ? $record->photo_url : null;
                                $url ??= $component->getDisk()->url($file);

                                return [
                                    'name' => basename($file),
                                    'size' => 0,
                                    'type' => null,
                                    'url' => $url,
                                ];
                            })
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

                        Forms\Components\Toggle::make('is_active')
                            ->label('Status Aktif')
                            ->default(true)
                            ->helperText('Nonaktifkan kalau kios ini stop/tutup'),

                        // Saat CREATE, jenis kedai dipilih lewat radio "Jenis Kedai" di atas
                        // (yang mengeset is_cash_only). Di EDIT, owner bisa ubah manual di sini.
                        // ->live() supaya toggle cash-only langsung menyembunyikan field Jatah
                        // Titipan di section atas (satu field mika, relevan hanya utk konsinyasi).
                        Forms\Components\Toggle::make('is_cash_only')
                            ->label('Kios Bayar Tunai Langsung (Cash-Only)')
                            ->default(false)
                            ->live()
                            ->visibleOn('edit')
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
                    // SATU sumber URL foto — Kiosk::photo_url, SAMA PERSIS dipakai operator
                    // (active-trip.blade.php). Dua jalur beda (dulu: ImageColumn generate URL
                    // sendiri via disk/exists-check, operator pakai accessor) terbukti jadi
                    // sumber diagnosa panjang & berlapis. State berupa URL penuh (proxy
                    // same-origin KioskPhotoController, BUKAN R2 langsung lagi) -> Filament
                    // ImageColumn otomatis pakai apa adanya (getImageUrl() short-circuit utk
                    // state yang sudah valid URL), tak perlu ->disk()/->checkFileExistence()
                    // lagi sama sekali.
                    ->getStateUsing(fn (Kiosk $record): ?string => $record->photo_url)
                    ->defaultImageUrl(self::PHOTO_PLACEHOLDER)
                    // Retry sekali kalau gagal transient (proxy sendiri kena hiccup Railway<->R2,
                    // dsb) — jaring pengaman murah, tak ada downside.
                    ->extraImgAttributes([
                        'onerror' => "if(!this.dataset.retried){this.dataset.retried='1';var el=this;setTimeout(function(){el.src=el.src.split('?')[0]+'?retry='+Date.now();},400);}",
                    ])
                    ->square()
                    ->size(50),

                Tables\Columns\TextInputColumn::make('sort_order')
                    ->label('Urutan')
                    ->type('number')
                    ->rules(['nullable', 'integer', 'min:1'])
                    ->placeholder('—')
                    // Anti-duplikat: isi angka yang sudah dipakai kios LAIN di area yang
                    // sama TIDAK membuat kembar — kios lain otomatis tergeser (insert-
                    // within-list, lihat Kiosk::reorderWithinCluster). Tak pernah
                    // menyentuh kios di cluster lain.
                    ->updateStateUsing(function (Kiosk $record, $state): ?int {
                        $desired = $state === null ? null : (int) $state;

                        return Kiosk::reorderWithinCluster($record, $desired);
                    }),

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

                // TITIPAN / MODAL yang ada di kedai (jauh lebih berguna dari telepon).
                // 3 kondisi: cash-only → "Cash Only" (kuning); konsinyasi bertitipan →
                // "X mika" (hijau); konsinyasi TANPA titipan (anomali) → merah. Nilai
                // pending_titipan_mika di-agregasi via withSum di getEloquentQuery (1
                // subquery, BUKAN N+1) — telepon tetap ada di form create/edit, cuma
                // tak ditampilkan di tabel.
                Tables\Columns\TextColumn::make('pending_titipan_mika')
                    ->label('Titipan')
                    ->badge()
                    ->sortable()
                    ->getStateUsing(function (Kiosk $record): string {
                        if ($record->is_cash_only) {
                            return 'Cash Only';
                        }

                        $mika = (int) ($record->pending_titipan_mika ?? 0);

                        return $mika > 0 ? $mika.' mika' : 'Belum ada titipan';
                    })
                    ->color(function (Kiosk $record): string {
                        if ($record->is_cash_only) {
                            return 'warning';
                        }

                        return ((int) ($record->pending_titipan_mika ?? 0)) > 0 ? 'success' : 'danger';
                    })
                    ->icon(function (Kiosk $record): ?string {
                        if ($record->is_cash_only) {
                            return 'heroicon-m-banknotes';
                        }

                        return ((int) ($record->pending_titipan_mika ?? 0)) > 0
                            ? 'heroicon-m-inbox-arrow-down'
                            : 'heroicon-m-exclamation-triangle';
                    }),

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
            // Urutan rute pengantaran PER-AREA (pengalaman lapangan operator: seser
            // habis satu area dulu baru pindah), bukan abjad. Kios dikelompokkan per
            // cluster (subquery correlated, bukan JOIN, biar kolom kiosks.* tidak
            // tertimpa kolom clusters.* yang senama), lalu di dalam area diurut
            // sort_order — kios tanpa sort_order (belum diatur) turun ke bawah
            // DALAM AREA-nya, bukan ke atas atau tercampur ke area lain.
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderBy(Cluster::query()->select('name')->whereColumn('clusters.id', 'kiosks.cluster_id'))
                ->orderByRaw('sort_order IS NULL')
                ->orderBy('sort_order')
                ->orderBy('name'))
            // Drag HANYA aktif kalau TEPAT 1 Area sedang difilter — Filament native
            // reorder-mode mematikan grouping/defaultSort custom sepenuhnya saat aktif
            // (ORDER BY sort_order polos lintas SEMUA baris yang tampil), jadi drag
            // tanpa filter area bisa menomori ulang lintas-area. Tombol drag SEMBUNYI
            // total (bukan cuma nonaktif) kalau kondisi ini false — lihat
            // vendor/filament/tables/resources/views/index.blade.php:188 (`@if
            // ($isReorderable)`), jadi tak ada jalan salah secara fisik.
            ->reorderable('sort_order', fn ($livewire): bool => count($livewire->tableFilters['cluster_id']['values'] ?? []) === 1)
            ->filters([
                SelectFilter::make('cluster_id')
                    ->label('Area')
                    // 🔒 Filter juga di-scope owner (jangan bocorin nama area owner lain).
                    ->relationship('cluster', 'name', fn ($query) => $query
                        ->when(! (auth()->user()?->isSuperAdmin() ?? false),
                            fn ($q) => $q->where('owner_id', auth()->id())))
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

                // "Titipan berjalan" = ada Delivery konsinyasi PENDING (belum di-settle).
                // DIPERTAHANKAN untuk MONITORING (bukan lagi backfill): filter "Belum ada
                // titipan" cepat menemukan kedai konsinyasi yang kehilangan titipan (anomali)
                // — dipadu kolom "Titipan" merah. Bukan sekadar alat backfill.
                TernaryFilter::make('has_pending_titipan')
                    ->label('Titipan Berjalan')
                    ->placeholder('Semua')
                    ->trueLabel('Ada titipan')
                    ->falseLabel('Belum ada titipan')
                    ->queries(
                        true: fn ($query) => $query->whereHas('deliveries', fn ($q) => $q->doesntHave('settlement')),
                        false: fn ($query) => $query->whereDoesntHave('deliveries', fn ($q) => $q->doesntHave('settlement')),
                    ),
            ])
            ->actions([
                // CATATAN: action "Set Saldo Awal" DIHAPUS (13 Juli 2026) — sudah redundan.
                // Titipan berjalan kini di-set langsung saat BUAT KEDAI (owner: radio Jenis
                // Kedai → Konsinyasi + "titipan berjalan sekarang"; operator: create-kiosk di
                // trip), dan owner sudah selesai backfill semua kedai lama. Helper
                // App\Support\OpeningBalance TETAP dipakai jalur create tsb.
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

        // Total mika titipan BERJALAN (delivery tanpa settlement) per kios, untuk kolom
        // "Titipan". withSum = SATU subquery scalar berkorelasi di query utama (BUKAN
        // N+1) — aman untuk list ratusan/ribuan kios.
        $query->withSum(
            ['deliveries as pending_titipan_mika' => fn ($q) => $q->doesntHave('settlement')],
            'qty_delivered',
        );

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
