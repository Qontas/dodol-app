# PROMPT_FIX_001.md

## Konteks

File utama: resources/views/livewire/operator/create-kiosk.blade.php
Working dir: C:\Users\Qontas\Projects\dodol-app

## Fix 1 — Tombol "Simpan Kios" ketutupan peta

File: resources/views/livewire/operator/create-kiosk.blade.php

Cari div wrapper tombol submit (ada class "fixed bottom-16 inset-x-0 px-4 z-30")
Ubah z-30 → z-[9999]

Cari div map container (id="operator-map")
Tambah "z-index: 1; position: relative;" ke style inline-nya.

## Fix 2 — Session lifetime 8 jam

File: .env

Cari SESSION_LIFETIME, ubah nilainya jadi 480.
Kalau belum ada, tambahkan: SESSION_LIFETIME=480

## Fix 3 — Leaflet lokal (tidak bergantung internet)

Jalankan perintah ini untuk download Leaflet ke lokal:

- Buat folder public/vendor/leaflet/
- Download https://unpkg.com/leaflet@1.9.4/dist/leaflet.css → public/vendor/leaflet/leaflet.css
- Download https://unpkg.com/leaflet@1.9.4/dist/leaflet.js → public/vendor/leaflet/leaflet.js

Di create-kiosk.blade.php, ganti:
DARI:

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

KE:

<link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}" />
<script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>

## Fix 4 — Lokasi opsional + tombol "Pakai Lokasi Saya"

### Di create-kiosk.blade.php:

1. Ubah label lokasi:
   DARI: Lokasi Kios <span class="text-red-500">\*</span>
   KE: Lokasi Kios <span class="text-slate-400 font-normal">(opsional)</span>

2. Ubah teks instruksi:
   DARI: Klik titik di peta untuk menandai lokasi kios
   KE: Klik titik di peta atau pakai tombol GPS di bawah

3. Tambah tombol geolocation tepat di atas div wire:ignore map:
   <button type="button" id="btn-gps"
       class="mb-2 w-full py-2 rounded-xl border border-amber-400 text-amber-700 
              text-sm font-semibold bg-amber-50 hover:bg-amber-100 transition">
   📍 Pakai Lokasi Saya (GPS)
   </button>

4. Refactor bagian <script> — ubah variabel map dan marker jadi scope luar
   supaya bisa diakses tombol GPS. Struktur barunya:

<script>
    let operatorMap = null;
    let operatorMarker = null;

    document.addEventListener('livewire:navigated', initOperatorMap);
    document.addEventListener('DOMContentLoaded', initOperatorMap);

    function initOperatorMap() {
        const mapEl = document.getElementById('operator-map');
        if (!mapEl || mapEl._leaflet_id) return;
        if (typeof L === 'undefined') return;

        const startLat = {{ $latitude ?: 3.5952 }};
        const startLng = {{ $longitude ?: 98.6722 }};

        operatorMap = L.map('operator-map').setView([startLat, startLng], 15);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap'
        }).addTo(operatorMap);

        @if ($latitude && $longitude)
            operatorMarker = L.marker([startLat, startLng]).addTo(operatorMap);
        @endif

        operatorMap.on('click', function (e) {
            setMapLocation(e.latlng.lat, e.latlng.lng);
        });

        document.getElementById('btn-gps').addEventListener('click', function () {
            if (!navigator.geolocation) {
                alert('GPS tidak didukung browser ini.');
                return;
            }
            this.textContent = '⏳ Mencari lokasi...';
            const btn = this;
            navigator.geolocation.getCurrentPosition(
                function (pos) {
                    setMapLocation(pos.coords.latitude, pos.coords.longitude);
                    operatorMap.setView([pos.coords.latitude, pos.coords.longitude], 17);
                    btn.textContent = '📍 Pakai Lokasi Saya (GPS)';
                },
                function () {
                    alert('Gagal mendapatkan lokasi. Pastikan GPS aktif dan izin lokasi diberikan.');
                    btn.textContent = '📍 Pakai Lokasi Saya (GPS)';
                }
            );
        });
    }

    function setMapLocation(lat, lng) {
        if (operatorMarker) {
            operatorMarker.setLatLng([lat, lng]);
        } else {
            operatorMarker = L.marker([lat, lng]).addTo(operatorMap);
        }
        @this.set('latitude', lat);
        @this.set('longitude', lng);
    }
</script>

### Di app/Livewire/Operator/CreateKiosk.php:

Cari rules() atau array validasi, ubah latitude dan longitude:
DARI:
'latitude' => 'required|numeric',
'longitude' => 'required|numeric',
KE:
'latitude' => 'nullable|numeric',
'longitude' => 'nullable|numeric',

## Setelah semua fix:

1. Jalankan: php artisan test
2. Pastikan semua PASS (sebelumnya 155 PASS)
3. Kalau ada test gagal karena latitude/longitude required, update assertnya ke nullable
4. git add -A
5. git commit -m "fix: tombol simpan kios, session 8 jam, leaflet lokal, lokasi opsional+GPS"
6. Laporkan hasil test dan apakah ada error
