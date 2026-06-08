<div class="max-w-md mx-auto">
    {{-- Header --}}
    <div class="mb-6">
        <a href="{{ route('operator.dashboard') }}" wire:navigate
           class="text-slate-500 text-sm flex items-center gap-1 hover:text-slate-800">
            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M9.707 14.707a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 1.414L7.414 9H15a1 1 0 110 2H7.414l2.293 2.293a1 1 0 010 1.414z" clip-rule="evenodd"/>
            </svg>
            Kembali ke Dashboard
        </a>
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

        {{-- Cluster --}}
        <div>
            <label for="clusterId" class="block text-sm font-bold text-slate-900 mb-2">
                Cluster (opsional)
            </label>
            <select
                id="clusterId"
                wire:model="clusterId"
                class="w-full rounded-xl border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 py-3">
                <option value="">— Pilih cluster —</option>
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
                Default Qty Mika <span class="text-red-500">*</span>
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
                Lokasi Kios <span class="text-red-500">*</span>
            </label>
            <p class="text-xs text-slate-500 mb-2">
                Klik titik di peta untuk menandai lokasi kios
            </p>

            {{-- Map container — wire:ignore biar Livewire ga reset DOM peta saat update --}}
            <div wire:ignore>
                <div
                    id="operator-map"
                    style="height: 300px; border-radius: 12px; border: 1px solid #e2e8f0;"
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

        {{-- Tombol Simpan — sticky bottom --}}
        <div class="fixed bottom-16 inset-x-0 px-4 z-30">
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

    {{-- Leaflet --}}
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        document.addEventListener('livewire:navigated', initOperatorMap);
        document.addEventListener('DOMContentLoaded', initOperatorMap);

        function initOperatorMap() {
            const mapEl = document.getElementById('operator-map');
            if (!mapEl || mapEl._leaflet_id) return;
            if (typeof L === 'undefined') return;

            const startLat = {{ $latitude ?: 3.5952 }};
            const startLng = {{ $longitude ?: 98.6722 }};

            const map = L.map('operator-map').setView([startLat, startLng], 15);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap'
            }).addTo(map);

            let marker = null;
            @if ($latitude && $longitude)
                marker = L.marker([startLat, startLng]).addTo(map);
            @endif

            map.on('click', function (e) {
                const lat = e.latlng.lat;
                const lng = e.latlng.lng;

                if (marker) {
                    marker.setLatLng([lat, lng]);
                } else {
                    marker = L.marker([lat, lng]).addTo(map);
                }

                @this.set('latitude', lat);
                @this.set('longitude', lng);
            });
        }
    </script>
</div>
