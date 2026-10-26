<x-filament-forms::field-wrapper
    :id="$getId()"
    :label="$getLabel()"
    :label-sr-only="$isLabelHidden()"
    :helper-text="$getHelperText()"
    :hint="$getHint()"
    :required="$isRequired()"
    :state-path="$getStatePath()"
>
    <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}" />
    <script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
    {{-- Kebijakan GPS akurat (26 Okt 2026, lihat public/js/gps-accuracy.js) — SATU sumber
         kebenaran, dipakai juga oleh peta operator & "Urutkan Jarak". WAJIB dimuat sebelum
         leaflet-map-picker.js (dipakai di dalam useMyLocation/applyGpsResult). --}}
    <script src="{{ asset('js/gps-accuracy.js') }}"></script>
    <script src="{{ asset('js/leaflet-map-picker.js') }}"></script>

    <div
        wire:ignore
        x-data="leafletMapPicker($wire, {{ $getMapConfig() }})"
        x-init="init()"
    >
        {{-- Tombol prominent "Pakai Lokasi Saya (GPS)" — sejajar dgn tombol operator
             (create-kiosk). Memanggil useMyLocation() Alpine yang sama dgn kontrol peta:
             ambil GPS → geser pin → recenter + invalidateSize. --}}
        <div x-show="showGpsButton" class="mb-2">
            <button
                type="button"
                x-on:click="useMyLocation()"
                x-bind:disabled="gpsLoading"
                class="inline-flex w-full items-center justify-center gap-2 rounded-lg border border-amber-400 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-700 transition hover:bg-amber-100 disabled:opacity-60 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-400"
            >
                <span x-show="!gpsLoading">📍 Pakai Lokasi Saya (GPS)</span>
                <span x-show="gpsLoading" x-cloak>⏳ Mencari lokasi…</span>
            </button>
            {{-- Status pencarian ter-update tiap bacaan membaik ("Mencari… akurasi ±X m"). --}}
            <p x-show="gpsSearchingText" x-cloak x-text="gpsSearchingText" class="mt-1 text-xs text-gray-500 dark:text-gray-400"></p>
            <p x-show="gpsError" x-cloak x-text="gpsError" class="mt-1 text-xs text-danger-600 dark:text-danger-400"></p>
        </div>

        <div x-ref="map" class="w-full rounded-xl" style="min-height: 30vh;"></div>

        {{-- Tile gagal dimuat (CDN diblokir, dll) — peta & form tetap bisa dipakai. --}}
        <p x-show="tileError" x-cloak class="mt-2 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-xs font-semibold text-red-700 dark:border-red-500/40 dark:bg-red-500/10 dark:text-red-400">
            Gambar peta gagal dimuat. Periksa koneksi internet. Lokasi tetap bisa dipilih lewat GPS/tap peta.
        </p>

        {{-- Band 31–100 m: pin dipasang TAPI wajib konfirmasi, koordinat belum tersimpan
             sampai "Pakai lokasi ini" dipencet. --}}
        <div x-show="gpsPending !== null" x-cloak class="mt-2 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 dark:border-amber-500/40 dark:bg-amber-500/10">
            <p x-text="`Akurasi ±${gpsPending ? Math.round(gpsPending.accuracy) : 0} m — kurang presisi. Pastikan pin sudah di titik yang benar sebelum dipakai.`" class="text-xs font-semibold text-amber-800 dark:text-amber-400 mb-2"></p>
            <div class="flex gap-2">
                <button type="button" x-on:click="retryGps()" class="flex-1 py-2 rounded-lg border border-slate-300 bg-white text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200">
                    Coba lagi
                </button>
                <button type="button" x-on:click="confirmGpsPending()" x-text="`Pakai lokasi ini (±${gpsPending ? Math.round(gpsPending.accuracy) : 0} m)`" class="flex-1 py-2 rounded-lg bg-amber-600 text-white text-xs font-semibold hover:bg-amber-700"></button>
            </div>
        </div>

        {{-- Akurasi GPS — diisi Alpine (gpsAccuracy), bukan server. Lingkaran radius akurasi
             digambar di peta lewat updateGpsAccuracyUi(). Band ≤30m (otomatis) & 31-100m
             (setelah dikonfirmasi) tampilkan badge ini; band >100m lihat gpsError di atas. --}}
        <p x-show="gpsAccuracy !== null" x-cloak x-text="`Akurasi ±${gpsAccuracy} meter`" class="mt-2 text-xs text-gray-500 dark:text-gray-400"></p>
    </div>
</x-filament-forms::field-wrapper>
