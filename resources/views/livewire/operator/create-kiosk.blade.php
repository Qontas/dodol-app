<div class="max-w-md mx-auto">
    {{-- Banner offline: operator harus tahu koneksi putus, bukan spinner selamanya --}}
    <div wire:offline class="fixed top-0 inset-x-0 z-[60] bg-red-600 text-white text-center text-sm font-semibold py-2.5 shadow-md">
        Koneksi internet terputus. Periksa sinyal, lalu coba lagi.
    </div>

    {{-- Header --}}
    <div class="mb-6">
        @if ($hasActiveTrip)
            <a href="{{ route('operator.trip.active', $activeTripId) }}" wire:navigate
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
                Area <span class="text-red-500">*</span>
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

        {{-- JENIS KEDAI: konsinyasi (titip sekarang) / cash-only / booking (identitas saja) --}}
        <div>
            <label class="block text-sm font-bold text-slate-900 mb-2">
                Jenis Kedai <span class="text-red-500">*</span>
            </label>
            <div class="space-y-2">
                <label class="flex items-start gap-3 p-3 rounded-xl border cursor-pointer {{ $jenisKedai === 'konsinyasi' ? 'border-amber-400 bg-amber-50' : 'border-slate-200' }}">
                    <input type="radio" wire:model.live="jenisKedai" value="konsinyasi" class="mt-1 text-amber-600">
                    <span class="text-sm">
                        <span class="font-bold text-slate-900 block">🏪 Kedai Konsinyasi (titip sekarang)</span>
                        <span class="text-xs text-slate-500">Naruh dodol sekarang dari stok trip. Dihitung sebagai titipan berjalan + komisi.</span>
                    </span>
                </label>
                <label class="flex items-start gap-3 p-3 rounded-xl border cursor-pointer {{ $jenisKedai === 'cash_only' ? 'border-emerald-400 bg-emerald-50' : 'border-slate-200' }}">
                    <input type="radio" wire:model.live="jenisKedai" value="cash_only" class="mt-1 text-emerald-600">
                    <span class="text-sm">
                        <span class="font-bold text-slate-900 block">💵 Kedai Cash-Only (beli putus)</span>
                        <span class="text-xs text-slate-500">Beli tunai tiap kali, jumlah suka-suka. Tak ada titipan &amp; tak ada jatah.</span>
                    </span>
                </label>
                <label class="flex items-start gap-3 p-3 rounded-xl border cursor-pointer {{ $jenisKedai === 'booking' ? 'border-sky-400 bg-sky-50' : 'border-slate-200' }}">
                    <input type="radio" wire:model.live="jenisKedai" value="booking" class="mt-1 text-sky-600">
                    <span class="text-sm">
                        <span class="font-bold text-slate-900 block">📌 Kedai Booking (belum ada dodol)</span>
                        <span class="text-xs text-slate-500">Catat identitasnya dulu. Nanti pas dikunjungi, tentukan mau dititip atau cash.</span>
                    </span>
                </label>
            </div>
            @error('jenisKedai')
                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
            @enderror
        </div>

        {{-- KONSINYASI + TRIP AKTIF: field Jatah Titipan. Dodol yang ditaruh dicatat ke TRIP
             AKTIF (stok berkurang + komisi). Kedai langsung bisa "Tagih + Titip Ulang". --}}
        @if($jenisKedai === 'konsinyasi' && $hasActiveTrip)
            <div class="rounded-xl border border-amber-200 bg-amber-50/60 p-4">
                <div>
                    <label for="defaultQtyMika" class="block text-sm font-bold text-slate-900 mb-2">
                        Jatah Titipan (mika ditaruh sekarang) <span class="text-red-500">*</span>
                    </label>
                    <input
                        type="number"
                        id="defaultQtyMika"
                        wire:model="defaultQtyMika"
                        min="1"
                        inputmode="numeric"
                        onfocus="this.select()"
                        class="w-full rounded-xl border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 py-3">
                    <p class="text-xs text-slate-500 mt-1">Berapa mika yang kamu taruh sekarang dari stok trip. Ini jadi titipan berjalan + dihitung komisi. Kamu yang cek sisanya pas kunjungan berikutnya.</p>
                    @error('defaultQtyMika')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        @endif

        {{-- KONSINYASI TANPA TRIP AKTIF: tak bisa menaruh dodol (sistem tak tahu stok mana
             yang keluar). Arahkan operator mulai trip dulu; kedai disimpan sebagai booking. --}}
        @if($jenisKedai === 'konsinyasi' && ! $hasActiveTrip)
            <div class="rounded-xl border border-sky-200 bg-sky-50 p-4">
                <p class="text-sm font-bold text-sky-800">Mau menitipkan dodol? Mulai trip dulu ya.</p>
                <p class="text-xs text-sky-700 mt-1">Tanpa trip aktif, kedai ini disimpan sebagai <b>booking</b> (identitas saja). Nanti pas trip jalan dan kamu kunjungi, baru bisa naruh dodol / cash.</p>
            </div>
        @endif

        {{-- CATATAN KEDAI (teks bebas) — karakteristik kedai, tampil menonjol ke operator. --}}
        <div>
            <label for="storeNote" class="block text-sm font-bold text-slate-900 mb-2">
                Catatan Kedai <span class="text-slate-400 font-normal">(opsional)</span>
            </label>
            <textarea
                id="storeNote"
                wire:model="storeNote"
                rows="2"
                maxlength="500"
                placeholder="cth: Cash-only, biasa minta 5 mika / Suka-suka, kadang banyak kadang dikit"
                class="w-full rounded-xl border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 py-3"></textarea>
            <p class="text-xs text-slate-500 mt-1">Kebiasaan kedai ini. Tampil menonjol biar operator berikutnya tahu.</p>
            @error('storeNote')
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

            {{-- Tempel link/koordinat Google Maps → auto-loncat --}}
            <div class="mb-3">
                <div class="flex gap-2">
                    <input
                        type="text"
                        wire:model="mapsInput"
                        placeholder="Tempel link atau koordinat Google Maps"
                        class="flex-1 min-w-0 rounded-xl border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 py-2.5 text-sm">
                    <button
                        type="button"
                        wire:click="jumpToMapsLocation"
                        wire:loading.attr="disabled"
                        wire:target="jumpToMapsLocation"
                        class="px-3 rounded-xl bg-slate-800 text-white text-sm font-semibold hover:bg-slate-700 disabled:opacity-60 whitespace-nowrap">
                        <span wire:loading.remove wire:target="jumpToMapsLocation">Loncat</span>
                        <span wire:loading wire:target="jumpToMapsLocation">⏳</span>
                    </button>
                </div>
                @error('mapsInput')
                    <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                @enderror
            </div>

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
                <p id="map-tile-error" class="hidden text-xs text-amber-600 mt-2">
                    ⚠️ Gambar peta gagal dimuat (jaringan). Kamu masih bisa menandai lokasi lewat tombol GPS atau tempel link Google Maps, atau simpan kios tanpa lokasi.
                </p>
                {{-- Akurasi GPS — diisi JS (gpsBestAccuracy), bukan server. Lingkaran radius
                     akurasi digambar di peta (L.circle) lewat updateGpsAccuracyUi(). --}}
                <p id="gps-accuracy" class="hidden text-xs text-slate-500 mt-2"></p>
                <p id="gps-accuracy-warning" class="hidden text-xs font-semibold text-amber-800 bg-amber-50 border border-amber-300 rounded-lg px-3 py-2 mt-2"></p>
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
        {{-- Kompres di browser SEBELUM upload — foto kamera HP bisa 8–17MB; dikecilkan
             dulu (resources/js/kiosk-photo.js: canvas, sisi terpanjang 1600px, JPEG 0.8,
             tanpa library) supaya operator tak perlu memikirkan ukuran file. Kalau
             kompres tak bisa (mis. HEIC di Android), file asli tetap dikirim & SERVER
             yang mengonversi/mengecilkan — jadi tak pernah buntu di sini. --}}
        <div x-data="{
            preparing: false,
            async handleFoto(e) {
                const file = e.target.files[0];
                if (!file) return;
                this.preparing = true;
                try {
                    const out = await window.kompresFotoKios(file);
                    await $wire.upload('foto', out);
                } finally {
                    this.preparing = false;
                }
            }
        }">
            <label class="block text-sm font-bold text-slate-900 mb-2">
                Foto Kios <span class="text-slate-400 font-normal">(opsional)</span>
            </label>

            {{-- DUA jalur, SENGAJA dua input terpisah (bukan satu input dengan capture):
                 atribut `capture` MEMAKSA kamera & MENGHILANGKAN pilihan galeri di HP.
                 Jadi "Ambil Foto" = input ber-capture (langsung buka kamera belakang, tak
                 usah keluar app), "Dari Galeri" = input polos (perilaku LAMA, tak berubah).
                 Di desktop `capture` diabaikan browser → dua-duanya jadi file picker biasa.
                 Keduanya masuk ke handleFoto yang sama → kompres & upload tak berubah. --}}
            <div class="grid grid-cols-2 gap-2">
                <button type="button" x-on:click="$refs.fotoKamera.click()" :disabled="preparing"
                        class="py-2.5 rounded-xl bg-amber-600 text-white text-sm font-semibold
                               hover:bg-amber-700 active:scale-[0.98] transition disabled:opacity-60">
                    📷 Ambil Foto
                </button>
                <button type="button" x-on:click="$refs.fotoGaleri.click()" :disabled="preparing"
                        class="py-2.5 rounded-xl border border-amber-400 text-amber-700 text-sm font-semibold
                               bg-amber-50 hover:bg-amber-100 active:scale-[0.98] transition disabled:opacity-60">
                    🖼️ Dari Galeri
                </button>
            </div>

            <input type="file" accept="image/*" capture="environment" x-ref="fotoKamera"
                   class="hidden" x-on:change="handleFoto($event)">
            <input type="file" accept="image/*" x-ref="fotoGaleri"
                   class="hidden" x-on:change="handleFoto($event)">

            <div x-show="preparing" class="mt-2 text-xs text-amber-600">Menyiapkan foto…</div>
            <div wire:loading wire:target="foto" class="mt-2 text-xs text-amber-600">Mengunggah foto…</div>
            {{-- Pratinjau HANYA untuk format yang bisa dirender browser. temporaryUrl()
                 MELEMPAR FileNotPreviewableException untuk ekstensi di luar
                 livewire.temporary_file_upload.preview_mimes (mis. .heic) → halaman
                 operator 500 begitu HEIC dipilih, SEBELUM validasi sempat jalan.
                 Ketahuan dari test, bukan dari lapangan. HEIC dapat konfirmasi teks
                 (bukan gambar rusak) — file-nya dikonversi ke JPG saat disimpan. --}}
            @if($foto)
                <div class="mt-2">
                    @if($foto->isPreviewable())
                        <img
                            src="{{ $foto->temporaryUrl() }}"
                            alt="Pratinjau foto kios"
                            class="w-full max-h-48 object-cover rounded-xl border border-slate-200">
                    @else
                        <p class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                            ✅ Foto terpilih ({{ $foto->getClientOriginalName() }}). Pratinjau tak tersedia
                            untuk format ini — foto dikonversi otomatis saat disimpan.
                        </p>
                    @endif
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
        let operatorAccuracyCircle = null;

        document.addEventListener('livewire:navigated', initOperatorMap);
        document.addEventListener('DOMContentLoaded', initOperatorMap);

        function initOperatorMap() {
            const mapEl = document.getElementById('operator-map');
            if (!mapEl || mapEl._leaflet_id) return;
            if (typeof L === 'undefined') return;

            const startLat = {{ $latitude ?: 3.5952 }};
            const startLng = {{ $longitude ?: 98.6722 }};

            operatorMap = L.map('operator-map', { maxZoom: 19 }).setView([startLat, startLng], 15);

            // Tile OpenStreetMap standar (20 Okt 2026, ganti dari CARTO Voyager): CARTO
            // sekarang mewajibkan API key di zoom dekat (z≥~17) — tile diganti watermark
            // "API KEY REQUIRED" bukan dirender, itu AKAR "peta hilang" di lapangan saat
            // operator GPS/zoom dekat (zoom jauh masih normal, jadi lolos dari cek sekilas).
            // Host tunggal (tak pakai {s}/subdomains). maxNativeZoom 19 = batas tile OSM
            // ada; maxZoom 19 = user tak bisa over-zoom ke zona tanpa-tile. Samakan dgn
            // peta owner (KioskResource/LeafletMapPicker) biar konsisten.
            const operatorTiles = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors',
                maxNativeZoom: 19,
                maxZoom: 19
            }).addTo(operatorMap);

            // Tile gagal muat (CDN diblokir jaringan/kartu SIM tertentu, dll) -> jangan
            // diamkan jadi kotak abu-abu misterius. Tampilkan pesan SEKALI, peta &
            // form tetap bisa dipakai (lokasi selalu opsional, lihat komentar di atas).
            let tileErrorShown = false;
            operatorTiles.on('tileerror', function () {
                if (tileErrorShown) return;
                tileErrorShown = true;
                const msg = document.getElementById('map-tile-error');
                if (msg) msg.classList.remove('hidden');
            });

            @if ($latitude && $longitude)
                operatorMarker = createMarker(startLat, startLng);
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
        //
        // RIWAYAT BUG (TERBUKTI dari keluhan lapangan 21 Okt 2026): versi sebelumnya pakai
        // getCurrentPosition SEKALI, dan kalau gagal (bukan izin ditolak) langsung retry SEKALI
        // dgn enableHighAccuracy:false lalu PASRAH menerima hasilnya — fix jaringan/wifi bisa
        // meleset ratusan meter tapi diam-diam dianggap tepat (operator sudah refresh GPS >2x,
        // tetap meleset, karena hasil kasar itu TAK PERNAH diganti walau GPS sejati menyusul).
        //
        // Sekarang pakai watchPosition maks 15 detik, SIMPAN & PAKAI bacaan dgn coords.accuracy
        // TERKECIL (bisa makin akurat tiap update selama GPS chip mengunci), berhenti lebih awal
        // begitu akurasi ≤20m. Kalau sampai batas waktu tak pernah dapat ≤20m, bacaan terbaik yang
        // ada tetap dipakai TAPI ditandai jelas sbg perkiraan kasar (pesan kuning + lingkaran
        // akurasi di peta) — bukan lagi dianggap diam-diam tepat. watchPosition WAJIB dihentikan
        // (clearWatch) begitu selesai ATAU saat operator pindah halaman (lihat listener
        // livewire:navigate di bawah) — kalau tidak, pencarian lanjut jalan di background sia-sia.
        let gpsWatchId = null;
        let gpsTimeoutId = null;
        let gpsBestAccuracy = null;
        let gpsGotAnyFix = false;

        function triggerGps(isAuto) {
            const btn = document.getElementById('btn-gps');
            if (!navigator.geolocation) {
                if (!isAuto) alert('GPS tidak didukung browser ini.');
                return;
            }
            stopGpsWatch();
            gpsBestAccuracy = null;
            gpsGotAnyFix = false;
            if (btn) btn.textContent = '⏳ Mencari lokasi...';

            gpsWatchId = navigator.geolocation.watchPosition(
                function (pos) {
                    const acc = pos.coords.accuracy;
                    if (gpsBestAccuracy === null || acc < gpsBestAccuracy) {
                        gpsBestAccuracy = acc;
                        gpsGotAnyFix = true;
                        setMapLocation(pos.coords.latitude, pos.coords.longitude, acc);
                        if (operatorMap) operatorMap.setView([pos.coords.latitude, pos.coords.longitude], 17);
                    }
                    if (acc <= 20) {
                        finishGps(isAuto);
                    }
                },
                function (err) {
                    // Izin ditolak: percuma ditunggu/di-retry, langsung menyerah.
                    if (err && err.code === err.PERMISSION_DENIED) {
                        stopGpsWatch();
                        if (btn) btn.textContent = '📍 Pakai Lokasi Saya (GPS)';
                        if (!isAuto) {
                            alert('Izin lokasi ditolak. Buka ikon gembok di address bar > Izin > Lokasi > Izinkan, lalu coba lagi. Kamu tetap bisa simpan kios tanpa lokasi atau tandai manual di peta.');
                        }
                        return;
                    }
                    // Error lain (sinyal lemah/tak tersedia sesaat) — watchPosition tetap bisa
                    // dapat fix berikutnya; biarkan jalan sampai batas waktu 15 detik di bawah
                    // yang memutuskan, jangan menyerah di error pertama (itu akar bug lama).
                },
                { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
            );

            gpsTimeoutId = setTimeout(function () {
                finishGps(isAuto);
            }, 15000);
        }

        // Dipanggil begitu akurasi ≤20m ATAU batas waktu 15 detik habis (mana dulu).
        function finishGps(isAuto) {
            stopGpsWatch();
            const btn = document.getElementById('btn-gps');
            if (btn) btn.textContent = '📍 Pakai Lokasi Saya (GPS)';
            if (!gpsGotAnyFix && !isAuto) {
                alert('Gagal mendapatkan lokasi. Sinyal GPS tidak didapat dalam 15 detik. Coba lagi di tempat terbuka, atau tandai lokasi manual di peta.');
            }
        }

        function stopGpsWatch() {
            if (gpsWatchId !== null) {
                navigator.geolocation.clearWatch(gpsWatchId);
                gpsWatchId = null;
            }
            if (gpsTimeoutId !== null) {
                clearTimeout(gpsTimeoutId);
                gpsTimeoutId = null;
            }
        }

        // Operator bisa pindah halaman (wire:navigate, mis. tombol kembali di header) sebelum
        // GPS selesai/timeout — watch yang masih jalan harus dihentikan, bukan dibiarkan bocor
        // di background tanpa UI yang mengonsumsi hasilnya.
        document.addEventListener('livewire:navigate', stopGpsWatch);

        function createMarker(lat, lng) {
            const marker = L.marker([lat, lng], { draggable: true }).addTo(operatorMap);
            marker.on('dragend', function () {
                const pos = marker.getLatLng();
                // Pin digeser manual oleh operator -> dianggap koreksi yang disengaja,
                // bukan lagi bacaan GPS mentah. Lepas badge/lingkaran akurasi GPS.
                clearGpsAccuracyUi();
                setMapLocation(pos.lat, pos.lng);
            });
            return marker;
        }

        function moveMarker(lat, lng) {
            if (operatorMarker) {
                operatorMarker.setLatLng([lat, lng]);
            } else {
                operatorMarker = createMarker(lat, lng);
            }
        }

        // accuracy diisi HANYA saat lokasi datang dari GPS (triggerGps). Klik peta / geser pin
        // manual memanggil fungsi ini TANPA accuracy -> otomatis bersihkan badge GPS lama.
        function setMapLocation(lat, lng, accuracy) {
            moveMarker(lat, lng);
            @this.set('latitude', lat);
            @this.set('longitude', lng);
            if (typeof accuracy === 'number') {
                updateGpsAccuracyUi(lat, lng, accuracy);
            } else {
                clearGpsAccuracyUi();
            }
        }

        // Tampilkan "Akurasi ±X meter" di bawah peta + lingkaran radius akurasi (L.circle).
        // >50m dianggap kurang akurat -> pesan kuning eksplisit (jangan diam-diam dianggap tepat).
        function updateGpsAccuracyUi(lat, lng, accuracy) {
            const info = document.getElementById('gps-accuracy');
            const warn = document.getElementById('gps-accuracy-warning');
            const rounded = Math.round(accuracy);

            if (info) {
                info.textContent = `Akurasi ±${rounded} meter`;
                info.classList.remove('hidden');
            }
            if (warn) {
                if (accuracy > 50) {
                    warn.textContent = `⚠️ Lokasi kurang akurat (±${rounded} m). Pastikan GPS mode akurasi tinggi, coba di luar ruangan, atau geser pin ke titik yang benar.`;
                    warn.classList.remove('hidden');
                } else {
                    warn.classList.add('hidden');
                }
            }
            if (operatorMap) {
                if (operatorAccuracyCircle) operatorMap.removeLayer(operatorAccuracyCircle);
                operatorAccuracyCircle = L.circle([lat, lng], {
                    radius: accuracy,
                    color: '#3b82f6',
                    weight: 1,
                    fillColor: '#3b82f6',
                    fillOpacity: 0.12,
                }).addTo(operatorMap);
            }
        }

        function clearGpsAccuracyUi() {
            const info = document.getElementById('gps-accuracy');
            const warn = document.getElementById('gps-accuracy-warning');
            if (info) info.classList.add('hidden');
            if (warn) warn.classList.add('hidden');
            if (operatorMap && operatorAccuracyCircle) {
                operatorMap.removeLayer(operatorAccuracyCircle);
                operatorAccuracyCircle = null;
            }
        }

        // Hasil tombol "Loncat" (jumpToMapsLocation di server sudah set
        // $latitude/$longitude dalam request yang sama) — tinggal geser peta,
        // TIDAK perlu @this.set lagi di sini (hindari round-trip dobel).
        document.addEventListener('livewire:init', () => {
            Livewire.on('kiosk-location-jumped', (event) => {
                if (!operatorMap) return;
                stopGpsWatch();
                clearGpsAccuracyUi();
                moveMarker(event.lat, event.lng);
                operatorMap.setView([event.lat, event.lng], 17);
            });
        });
    </script>

    {{-- Toast sukses "Loncat ke lokasi" --}}
    <div
        x-data="{ show: false }"
        x-on:kiosk-location-jumped.window="show = true; setTimeout(() => show = false, 2500)"
        x-show="show"
        x-transition
        style="display: none;"
        class="fixed top-4 inset-x-4 z-[9999] bg-emerald-600 text-white text-sm font-semibold rounded-xl px-4 py-3 shadow-lg text-center">
        📍 Lokasi ditemukan!
    </div>
</div>
