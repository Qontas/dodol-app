# Brief: Operator Input Kios Baru

## KONTEKS

Day 7 dodol-app. 45 PASS, 113 assertions.
Operator (Rian) butuh input kios baru langsung di lapangan.
Bottom nav "Kios Baru" sudah ada tapi placeholder (belum ada route/halaman).

## BUSINESS RULES (LOCKED)

- Operator bisa input kios baru langsung di lapangan
- Field WAJIB: nama kios, nama pemilik, cluster, default qty mika, lokasi (lat/lng via map picker)
- Field OPTIONAL: telepon
- Setelah save: kios langsung aktif (is_active=true) dan bisa dikunjungi di trip berikutnya
- Kios baru otomatis assign ke operator yang input (created_by atau notes)
- Map picker wajib ada (sama seperti KioskResource di Filament admin)

## SCHEMA (verified)

Cek schema kiosks dulu:
php artisan tinker --execute="echo implode(',', \Schema::getColumnListing('kiosks'));"

Sesuaikan form fields dengan kolom actual.

## KOMPONEN YANG DIBUTUHKAN

### 1. Route baru di operator group (routes/web.php)

Route::get('/kiosks/create', \App\Livewire\Operator\CreateKiosk::class)->name('kiosks.create');

Tambahkan di dalam operator middleware group (setelah route trip.active).

### 2. Livewire Component: app/Livewire/Operator/CreateKiosk.php

Pattern sama dengan operator pages lain:

- #[Layout('layouts.operator')]
- Properties: namaKios, namaPemilik, telepon, clusterId, defaultQtyMika, latitude, longitude
- Method: saveKiosk() — validate + Kiosk::create() + redirect ke operator.dashboard
- Method: mount() — load clusters aktif untuk dropdown
- Validasi:
    - namaKios: required, string, max 255
    - namaPemilik: required, string, max 255
    - clusterId: required, exists:clusters,id
    - defaultQtyMika: required, integer, min 1
    - latitude: required, numeric, between:-90,90
    - longitude: required, numeric, between:-180,180
    - telepon: nullable, string, max 20

### 3. View: resources/views/livewire/operator/create-kiosk.blade.php

Mobile-first, light theme (sama dengan halaman operator lain).
Layout:

- Header: "Kios Baru" + back button ke dashboard
- Form fields:
    - Nama Kios (text input)
    - Nama Pemilik (text input)
    - Telepon (tel input, optional — placeholder "Opsional")
    - Cluster (select dropdown dari $clusters)
    - Default Qty Mika (number input, min 1)
    - Lokasi Kios — MAP PICKER
        - Pakai dotswan/filament-map-picker TIDAK BISA di Livewire biasa
        - Pakai Leaflet.js langsung via CDN di view
        - CDN: https://unpkg.com/leaflet@1.9.4/dist/leaflet.css + leaflet.js
        - Map center default: Medan (lat: 3.5952, lng: 98.6722), zoom 13
        - User klik peta → marker muncul → lat/lng auto-fill ke hidden input
        - Tampilkan koordinat yang dipilih di bawah peta
        - wire:model untuk latitude + longitude (hidden inputs)
    - Error display per field
- Tombol "Simpan Kios" (amber, full width, sticky bottom)
- Loading state saat save

### 4. Update bottom nav (layouts/operator.blade.php)

Saat ini "Kios Baru" di nav masih placeholder (route null).
Update ke: 'route' => 'operator.kiosks.create'
Active state: request()->routeIs('operator.kiosks.\*')

### 5. saveKiosk() method

DB::transaction(function() {
Kiosk::create([
'name' => $this->namaKios,
'owner_name' => $this->namaPemilik, // sesuaikan dengan kolom actual
'phone' => $this->telepon ?: null,
'cluster_id' => $this->clusterId,
'default_qty_mika' => $this->defaultQtyMika,
'latitude' => $this->latitude,
'longitude' => $this->longitude,
'is_active' => true,
]);
});

session()->flash('kios_saved', 'Kios baru berhasil ditambahkan.');
redirect()->route('operator.dashboard');

PENTING: Sesuaikan field names dengan kolom actual dari schema check di Step 1.

## LEAFLET MAP IMPLEMENTATION (Livewire-compatible)

Karena Filament map picker tidak bisa dipakai di Livewire biasa, implementasi Leaflet manual:

```html
{{-- Di view, di section lokasi --}}
<div>
    <label class="block text-sm font-bold text-slate-900 mb-2">
        Lokasi Kios <span class="text-red-500">*</span>
    </label>
    <p class="text-xs text-slate-500 mb-2">
        Klik titik di peta untuk menandai lokasi kios
    </p>

    {{-- Map container --}}
    <div
        id="operator-map"
        style="height: 300px; border-radius: 12px; border: 1px solid #e2e8f0;"
    ></div>

    {{-- Koordinat display --}} @if($latitude && $longitude)
    <p class="text-xs text-slate-500 mt-2">
        📍 {{ number_format($latitude, 6) }}, {{ number_format($longitude, 6) }}
    </p>
    @else
    <p class="text-xs text-amber-600 mt-2">Belum ada lokasi dipilih</p>
    @endif {{-- Hidden inputs --}}
    <input type="hidden" wire:model="latitude" id="lat-input" />
    <input type="hidden" wire:model="longitude" id="lng-input" />

    @error('latitude')
    <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
    @enderror @error('longitude')
    <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
    @enderror
</div>

{{-- Leaflet scripts (di akhir view, sebelum closing div) --}}
<link
    rel="stylesheet"
    href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    document.addEventListener('livewire:navigated', initMap);
    document.addEventListener('DOMContentLoaded', initMap);

    function initMap() {
        const mapEl = document.getElementById('operator-map');
        if (!mapEl || mapEl._leaflet_id) return;

        const defaultLat = {{ $latitude ?: 3.5952 }};
        const defaultLng = {{ $longitude ?: 98.6722 }};

        const map = L.map('operator-map').setView([defaultLat, defaultLng], 15);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap'
        }).addTo(map);

        let marker = null;

        // Kalau sudah ada koordinat, tampilkan marker
        if ({{ $latitude ? 'true' : 'false' }}) {
            marker = L.marker([defaultLat, defaultLng]).addTo(map);
        }

        map.on('click', function(e) {
            const lat = e.latlng.lat;
            const lng = e.latlng.lng;

            if (marker) {
                marker.setLatLng([lat, lng]);
            } else {
                marker = L.marker([lat, lng]).addTo(map);
            }

            // Update Livewire
            @this.set('latitude', lat);
            @this.set('longitude', lng);
        });
    }
</script>
```

## STEP EKSEKUSI

1. Cek schema kiosks (field names actual)
2. Buat app/Livewire/Operator/CreateKiosk.php
3. Buat resources/views/livewire/operator/create-kiosk.blade.php (dengan Leaflet map)
4. Update routes/web.php (tambah route operator.kiosks.create)
5. Update layouts/operator.blade.php (bottom nav "Kios Baru" → route aktif)
6. php artisan test --compact (target 45+ PASS)
7. Commit:
   git add app/Livewire/Operator/CreateKiosk.php resources/views/livewire/operator/create-kiosk.blade.php routes/web.php resources/views/layouts/operator.blade.php
   git commit -m "feat(operator): input kios baru dari lapangan + leaflet map"

## STOP POINTS — TANYA ADVISOR KALAU

1. Schema kiosks punya field name berbeda dari yang diasumsikan (owner_name, phone, dll)
2. Test turun dari 45 PASS
3. Leaflet conflict dengan Alpine/Livewire
4. Route conflict dengan existing operator routes

JANGAN auto-decide. Lapor dulu.

Output: ringkas per step + test status. No narasi panjang.

Mulai sekarang.

--- END OF BRIEF ---
