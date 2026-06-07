# Brief: Link GPS + Foto Kios

## KONTEKS

45 PASS. Tambah 2 fitur ke kios:

1. Link GPS (Google Maps) dari lat/lng — muncul di admin + operator
2. Verify + fix foto upload di KioskResource

## BUSINESS RULES

- Link GPS format: https://www.google.com/maps?q={lat},{lng}
- Tampil di: Filament admin list kios (kolom) + operator active trip (tap kios → tombol navigasi)
- Foto kios: upload via Filament admin, tampil di operator saat modal visit dibuka
- Kalau lat/lng null = tidak tampilkan link GPS

## STEP EKSEKUSI

### Step 1: Cek existing KioskResource + ActiveTrip

Baca file:

- app/Filament/Resources/KioskResource.php (section table columns + form)
- app/Livewire/Operator/ActiveTrip.php (openVisitModal + view)
- resources/views/livewire/operator/active-trip.blade.php (modal visit section)

Cek apakah photo_path sudah ada di KioskResource form (FileUpload field).
Cek apakah storage symlink sudah ada: ls public/storage

### Step 2: Link GPS di Filament Admin (KioskResource table)

Tambah kolom di table() KioskResource:

IconColumn atau TextColumn dengan URL:

- Kalau lat + lng tidak null: tampilkan icon/link "Maps" yang open Google Maps
- Kalau null: tampilkan dash

Pattern:
TextColumn::make('maps_link')
->label('GPS')
->getStateUsing(fn($record) =>
        ($record->latitude && $record->longitude)
            ? "https://www.google.com/maps?q={$record->latitude},{$record->longitude}"
            : null
    )
    ->url(fn($record) =>
($record->latitude && $record->longitude)
            ? "https://www.google.com/maps?q={$record->latitude},{$record->longitude}"
: null
)
->openUrlInNewTab()
->label('Maps')
->icon('heroicon-o-map-pin')
->default('—')

### Step 3: Link GPS di Operator (ActiveTrip modal visit)

Di resources/views/livewire/operator/active-trip.blade.php:
Dalam modal visit, setelah nama kios (selectedKiosk), tambah tombol navigasi:

@if($selectedKiosk && $selectedKiosk->latitude && $selectedKiosk->longitude)
<a href="https://www.google.com/maps?q={{ $selectedKiosk->latitude }},{{ $selectedKiosk->longitude }}"
   target="_blank"
   class="inline-flex items-center gap-2 text-sm font-medium text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 hover:bg-amber-100">
<svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
</svg>
Navigasi ke Kios
</a>
@endif

### Step 4: Foto Kios — Verify + Fix

Cek apakah storage symlink ada:
php artisan tinker --execute="echo file_exists(public_path('storage')) ? 'symlink exists' : 'NO SYMLINK';"

Kalau tidak ada: php artisan storage:link

Cek KioskResource form apakah FileUpload sudah ada untuk photo_path.
Kalau belum ada: tambah FileUpload field di section "Foto & Konfigurasi":

FileUpload::make('photo_path')
->label('Foto Kios')
->image()
->imageResizeMode('cover')
->imageCropAspectRatio('16:9')
->imageResizeTargetWidth('800')
->imageResizeTargetHeight('450')
->directory('kiosk-photos')
->visibility('public')
->columnSpanFull()

Tambah foto display di operator modal visit:
@if($selectedKiosk && $selectedKiosk->photo_path)
<img src="{{ Storage::url($selectedKiosk->photo_path) }}"
alt="Foto {{ $selectedKiosk->name }}"
class="w-full rounded-xl object-cover" style="max-height: 160px;">
@endif

### Step 5: php artisan test --compact (target 45+ PASS)

### Step 6: Commit

git add app/Filament/Resources/KioskResource.php resources/views/livewire/operator/active-trip.blade.php
git commit -m "feat(kiosk): GPS link navigasi + foto kios di admin dan operator"

## STOP POINTS

1. FileUpload foto sudah ada tapi config berbeda
2. TextColumn maps_link conflict dengan existing columns
3. Test turun dari 45 PASS
4. Storage symlink gagal dibuat

JANGAN auto-decide. Lapor dulu.

Output: ringkas per step + test status. No narasi panjang.

Mulai sekarang.

--- END OF BRIEF ---
