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
            <p x-show="gpsError" x-cloak x-text="gpsError" class="mt-1 text-xs text-danger-600 dark:text-danger-400"></p>
        </div>

        <div x-ref="map" class="w-full rounded-xl" style="min-height: 30vh;"></div>

        {{-- Akurasi GPS — diisi Alpine (gpsAccuracy), bukan server. Lingkaran radius akurasi
             digambar di peta lewat updateGpsAccuracyUi(). >50m dianggap kurang akurat. --}}
        <p x-show="gpsAccuracy !== null" x-cloak x-text="`Akurasi ±${gpsAccuracy} meter`" class="mt-2 text-xs text-gray-500 dark:text-gray-400"></p>
        <p
            x-show="gpsAccuracy !== null && gpsAccuracy > 50"
            x-cloak
            x-text="`⚠️ Lokasi kurang akurat (±${gpsAccuracy} m). Pastikan GPS mode akurasi tinggi, coba di luar ruangan, atau geser pin ke titik yang benar.`"
            class="mt-2 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-400"
        ></p>
    </div>
</x-filament-forms::field-wrapper>
