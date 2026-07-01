<div class="max-w-md mx-auto">
    {{-- Banner offline: operator harus tahu koneksi putus, bukan spinner selamanya --}}
    <div wire:offline class="fixed top-0 inset-x-0 z-[60] bg-red-600 text-white text-center text-sm font-semibold py-2.5 shadow-md">
        Koneksi internet terputus. Periksa sinyal, lalu coba lagi.
    </div>

    {{-- Header --}}
    <div class="mb-6">
        @php
            $activeTrip = \App\Models\Trip::where('operator_id', auth()->id())
                ->whereDate('trip_date', today())
                ->whereNotNull('started_at')
                ->whereNull('ended_at')
                ->first();
        @endphp
        @if ($activeTrip)
            <a href="{{ route('operator.trip.active', $activeTrip->id) }}" wire:navigate
               class="text-slate-500 text-sm flex items-center gap-1 hover:text-slate-800">
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M9.707 14.707a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 1.414L7.414 9H15a1 1 0 110 2H7.414l2.293 2.293a1 1 0 010 1.414z" clip-rule="evenodd"/>
                </svg>
                Kembali ke Trip Aktif
            </a>
        @else
            <a href="{{ route('operator.dashboard') }}" wire:navigate
               class="text-slate-500 text-sm flex items-center gap-1 hover:text-slate-800">
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M9.707 14.707a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 1.414L7.414 9H15a1 1 0 110 2H7.414l2.293 2.293a1 1 0 010 1.414z" clip-rule="evenodd"/>
                </svg>
                Kembali ke Dashboard
            </a>
        @endif
        <h1 class="text-2xl font-bold text-slate-900 mt-2">Kios Baru</h1>
        <p class="text-slate-500 text-sm">Daftarkan kios baru langsung dari lapangan</p>
    </div>

    <form wire:submit="saveKiosk" class="space-y-5 pb-28">
        {{-- Nama Kios --}}
        <div>
            <label for="namaKios" class="block text-sm font-bold text-slate-900 mb-2">
                Nama Kios <span class="text-red-500">*</span>
            </label>
            <input
                type="text"
                id="namaKios"
                wire:model="namaKios"
                placeholder="Contoh: Kios Bu Sri"
                class="w-full rounded-xl border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 py-3">
            @error('namaKios')
                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
            @enderror
        </div>

        {{-- Nama Pemilik --}}
        <div>
            <label for="namaPemilik" class="block text-sm font-bold text-slate-900 mb-2">
                Nama Pemilik <span class="text-red-500">*</span>
            </label>
            <input
                type="text"
                id="namaPemilik"
                wire:model="namaPemilik"
                placeholder="Contoh: Sri Wahyuni"
                class="w-full rounded-xl border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 py-3">
            @error('namaPemilik')
                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
            @enderror
        </div>

        {{-- Telepon --}}
        <div>
            <label for="telepon" class="block text-sm font-bold text-slate-900 mb-2">
                Telepon
            </label>
            <input
                type="tel"
                id="telepon"
                wire:model="telepon"
                inputmode="tel"
                placeholder="Opsional"
                class="w-full rounded-xl border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 py-3">
            @error('telepon')
                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
            @enderror
        </div>

        {{-- Area --}}
        <div>
            <label for="clusterId" class="block text-sm font-bold text-slate-900 mb-2">
                Area (opsional)
            </label>
            <select
                id="clusterId"
                wire:model="clusterId"
                class="w-full rounded-xl border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 py-3">
                <option value="">— Pilih area —</option>
                @foreach ($clusters as $cluster)
                    <option value="{{ $cluster->id }}">{{ $cluster->name }}</option>
                @endforeach
            </select>
            @error('clusterId')
                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
            @enderror
        </div>

        {{-- Default Qty Mika --}}
        <div>
            <label for="defaultQtyMika" class="block text-sm font-bold text-slate-900 mb-2">
                Jumlah Mika Biasanya <span class="text-red-500">*</span>
            </label>
            <input
                type="number"
                id="defaultQtyMika"
                wire:model="defaultQtyMika"
                min="1"
                inputmode="numeric"
                class="w-full rounded-xl border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 py-3">
            @error('defaultQtyMika')
                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
            @enderror
        </div>

        {{-- Lokasi Kios — MAP PICKER --}}
        <div>
            <label class="block text-sm font-bold text-slate-900 mb-2">
                Lokasi Kios <span class="text-slate-400 font-normal">(opsional)</span>
            </label>
            <p class="text-xs text-slate-500 mb-2">
                Klik titik di peta atau pakai tombol GPS di bawah
            </p>

            <button type="button" id="btn-gps"
                class="mb-2 w-full py-2 rounded-xl border border-amber-400 text-amber-700
                       text-sm font-semibold bg-amber-50 hover:bg-amber-100 transition">
                📍 Pakai Lokasi Saya (GPS)
            </button>

            {{-- Map container — wire:ignore biar Livewire ga reset DOM peta saat update --}}
            <div wire:ignore>
                <div
                    id="operator-map"
                    style="height: 300px; border-radius: 12px; border: 1px solid #e2e8f0; z-index: 1; position: relative;"
                ></div>
            </div>

            {{-- Koordinat display --}}
            @if ($latitude && $longitude)
                <p class="text-xs text-slate-500 mt-2">
                    📍 {{ number_format($latitude, 6) }}, {{ number_format($longitude, 6) }}
                </p>
            @else
                <p class="text-xs text-amber-600 mt-2">Belum ada lokasi dipilih</p>
            @endif

            {{-- Hidden inputs --}}
            <input type="hidden" wire:model="latitude" id="lat-input" />
            <input type="hidden" wire:model="longitude" id="lng-input" />

            @error('latitude')
                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
            @enderror
            @error('longitude')
                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
            @enderror
        </div>

        {{-- Foto Kios --}}
        {{-- Kompres di browser sebelum upload (canvas, tanpa library eksternal — andal
             di lapangan/PWA tanpa CDN). Sisi terpanjang ~1280px, JPEG ~0.7. Kalau
             kompres gagal (browser lama/error), file asli tetap diupload. --}}
        <div x-data="{
            preparing: false,
            handleFoto(e) {
                const file = e.target.files[0];
                if (!file) return;
                if (!file.type || !file.type.startsWith('image/')) { $wire.upload('foto', file); return; }
                this.preparing = true;
                this.compress(file)
                    .then((out) => { $wire.upload('foto', out); })
                    .catch(() => { $wire.upload('foto', file); })
                    .finally(() => { this.preparing = false; });
            },
            compress(file) {
                return new Promise((resolve, reject) => {
                    const url = URL.createObjectURL(file);
                    const img = new Image();
                    img.onload = () => {
                        URL.revokeObjectURL(url);
                        const maxSide = 1280;
                        let w = img.width, h = img.height;
                        if (w > maxSide || h > maxSide) {
                            if (w >= h) { h = Math.round(h * maxSide / w); w = maxSide; }
                            else { w = Math.round(w * maxSide / h); h = maxSide; }
                        }
                        const canvas = document.createElement('canvas');
                        canvas.width = w; canvas.height = h;
                        const ctx = canvas.getContext('2d');
                        if (!ctx) { reject(); return; }
                        ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, w, h); // cegah bg hitam PNG transparan
                        ctx.drawImage(img, 0, 0, w, h);
                        canvas.toBlob((blob) => {
                            if (!blob) { reject(); return; }
                            const name = (file.name || 'foto').replace(/\.[^.]+$/, '') + '.jpg';
                            resolve(new File([blob], name, { type: 'image/jpeg' }));
                        }, 'image/jpeg', 0.7);
                    };
                    img.onerror = () => { URL.revokeObjectURL(url); reject(); };
                    img.src = url;
                });
            }
        }">
            <label class="block text-sm font-bold text-slate-900 mb-2">
                Foto Kios <span class="text-slate-400 font-normal">(opsional)</span>
            </label>
            <input
                type="file"
                accept="image/*"
                x-on:change="handleFoto($event)"
                class="w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4
                       file:rounded-lg file:border-0 file:text-sm file:font-semibold
                       file:bg-amber-50 file:text-amber-700 hover:file:bg-amber-100">
            <div x-show="preparing" class="mt-2 text-xs text-amber-600">Menyiapkan foto…</div>
            <div wire:loading wire:target="foto" class="mt-2 text-xs text-amber-600">Mengunggah foto…</div>
            @if($foto)
                <div class="mt-2">
                    <img
                        src="{{ $foto->temporaryUrl() }}"
                        alt="Pratinjau foto kios"
                        class="w-full max-h-48 object-cover rounded-xl border border-slate-200">
                </div>
            @endif
            @error('foto')
                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
            @enderror
        </div>

        {{-- Tombol Simpan — sticky bottom --}}
        <div class="fixed bottom-16 inset-x-0 px-4 z-[9999]">
            <div class="max-w-md mx-auto">
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="saveKiosk"
                    class="w-full py-4 rounded-xl font-bold text-lg bg-amber-600 hover:bg-amber-700 text-white shadow-lg active:scale-[0.98] transition-all disabled:opacity-60 disabled:cursor-not-allowed">
                    <span wire:loading.remove wire:target="saveKiosk">Simpan Kios</span>
                    <span wire:loading wire:target="saveKiosk" class="inline-flex items-center gap-2">
                        <svg class="animate-spin h-5 w-5 text-white" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        Menyimpan...
                    </span>
                </button>
            </div>
        </div>
    </form>

    {{-- Leaflet (lokal — tidak bergantung internet) --}}
    <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}" />
    <script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
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

            operatorMap = L.map('operator-map', { maxZoom: 20 }).setView([startLat, startLng], 15);

            // Tile CARTO Voyager (gratis, CDN + subdomain abcd → tile keisi cepat, usage
            // lebih longgar dari OSM). maxNativeZoom 20 = batas tile ada; maxZoom 20 = user
            // tak bisa over-zoom ke zona tanpa-tile (akar grey saat zoom kuat). Samakan dgn
            // peta owner (KioskResource) biar konsisten.
            L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap, © CARTO',
                subdomains: 'abcd',
                maxNativeZoom: 20,
                maxZoom: 20
            }).addTo(operatorMap);

            @if ($latitude && $longitude)
                operatorMarker = L.marker([startLat, startLng]).addTo(operatorMap);
            @endif

            operatorMap.on('click', function (e) {
                setMapLocation(e.latlng.lat, e.latlng.lng);
            });

            document.getElementById('btn-gps').addEventListener('click', function () {
                triggerGps(false);
            });

            // Auto-GPS SEKALI saat form pertama kebuka & koordinat masih kosong (kios
            // baru). Dataset flag mencegah berulang tiap re-render/livewire:navigated.
            // JANGAN auto-trigger kalau sudah ada koordinat (mis. koreksi manual / edit) —
            // supaya titik yang sudah di-set operator tidak ketimpa.
            @if (! ($latitude && $longitude))
                if (!mapEl.dataset.autoGpsTried) {
                    mapEl.dataset.autoGpsTried = '1';
                    triggerGps(true);
                }
            @endif
        }

        // Ambil lokasi GPS, dipakai tombol manual & auto-trigger.
        // isAuto=true  (auto saat form kebuka): senyap kalau gagal/ditolak — jangan ganggu
        //              operator dengan popup; mereka masih bisa pencet tombol / klik peta.
        // isAuto=false (tombol dipencet): tampilkan alert kalau gagal (operator sengaja minta).
        function triggerGps(isAuto) {
            const btn = document.getElementById('btn-gps');
            if (!navigator.geolocation) {
                if (!isAuto) alert('GPS tidak didukung browser ini.');
                return;
            }
            if (btn) btn.textContent = '⏳ Mencari lokasi...';
            navigator.geolocation.getCurrentPosition(
                function (pos) {
                    setMapLocation(pos.coords.latitude, pos.coords.longitude);
                    if (operatorMap) operatorMap.setView([pos.coords.latitude, pos.coords.longitude], 17);
                    if (btn) btn.textContent = '📍 Pakai Lokasi Saya (GPS)';
                },
                function () {
                    if (!isAuto) alert('Gagal mendapatkan lokasi. Pastikan GPS aktif dan izin lokasi diberikan.');
                    if (btn) btn.textContent = '📍 Pakai Lokasi Saya (GPS)';
                }
            );
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
</div>
