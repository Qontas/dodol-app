# Brief: Map Picker — KioskResource GPS Input

## KONTEKS

Day 6 dodol-app. Sesi 0d — replace manual lat/lng TextInput di KioskResource
dengan Leaflet map picker. Plugin: dotswan/filament-map-picker (no API key).
Commit target: feat(admin): map picker for kiosk GPS input

## PROBLEM

KioskResource form section "Lokasi" punya 2 TextInput manual:

- TextInput::make('latitude')
- TextInput::make('longitude')
  Operator harus cari koordinat Google Maps dulu, copy-paste manual = 30+ detik/kios.

## GOAL

Replace dengan 1 Map component → klik titik di peta → lat/lng auto-fill.
Default center: Medan, North Sumatra (3.5952, 98.6722), zoom 13.

## STEP EKSEKUSI

### Step 1: Install plugin

composer require dotswan/filament-map-picker

Kalau ada dependency conflict dengan Filament v3.3.50 → STOP dan lapor.

### Step 2: Publish assets (kalau ada)

php artisan vendor:publish --tag="filament-map-picker-assets" 2>&1 || true
php artisan vendor:publish --tag="filament-map-picker-config" 2>&1 || true

Kalau tidak ada tag itu → skip, lanjut.

### Step 3: Cek existing KioskResource form

Baca app/Filament/Resources/KioskResource.php — cari section "Lokasi"
(latitude + longitude fields). Catat exact method chain yang ada.

### Step 4: Replace lat/lng fields

Di KioskResource.php, replace:

- TextInput::make('latitude') + TextInput::make('longitude')

Dengan Map component dari dotswan:

use Dotswan\FilamentMapPicker\Fields\Map;

Map::make('location')
->label('Lokasi Kios')
->defaultLocation(latitude: 3.5952, longitude: 98.6722)
->defaultZoom(13)
->draggable()
->clickable()
->afterStateUpdated(function (Set $set, ?array $state): void {
        $set('latitude', $state['lat']);
        $set('longitude', $state['lng']);
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
->extraControl(['zoomDelta' => 1, 'zoomSnap' => 2]),

Pastikan import Set:
use Filament\Forms\Set;

CATATAN: API Map::make() bisa berbeda tergantung versi plugin.
Cek vendor/dotswan/filament-map-picker/src/Fields/Map.php untuk method yang tersedia.
Jangan paksa method yang tidak ada → sesuaikan dengan API actual.

### Step 5: Pastikan latitude + longitude tetap di form sebagai Hidden fields

Lat/lng masih disimpan ke DB sebagai kolom terpisah.
Tambah setelah Map component:

Hidden::make('latitude'),
Hidden::make('longitude'),

Import: use Filament\Forms\Components\Hidden;

### Step 6: Verify KioskResource $fillable / schema

Cek app/Models/Kiosk.php → latitude + longitude ada di $fillable.
Cek schema: php artisan tinker --execute="echo implode(',', \Schema::getColumnListing('kiosks'));"
Kalau latitude/longitude belum ada di schema → buat migration dulu.

### Step 7: php artisan test --compact

Target: 45+ PASS. Kalau turun → lapor, jangan auto-fix.

### Step 8: Manual verify (instruksi untuk advisor, bukan Claude Code)

Advisor akan test manual:

- Buka /admin/kiosks/create
- Verify map muncul (Leaflet, bukan blank)
- Klik titik di peta → lat/lng auto-fill di hidden fields
- Save kios → verify lat/lng tersimpan di DB

### Step 9: Commit

git add app/Filament/Resources/KioskResource.php composer.json composer.lock
git commit -m "feat(admin): map picker for kiosk GPS input (dotswan/filament-map-picker)"

## STOP POINTS — TANYA ADVISOR KALAU

1. Composer conflict dengan Filament v3.3.50
2. Map::make() API berbeda dari brief (method tidak ada)
3. Plugin butuh service provider manual register
4. Test turun dari 45 PASS
5. latitude/longitude tidak ada di kiosks schema

JANGAN auto-decide. Lapor dulu.

Output: ringkas per step + test status. No narasi panjang.

Mulai sekarang.

--- END OF BRIEF ---
