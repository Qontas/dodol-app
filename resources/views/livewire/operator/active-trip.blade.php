<div class="max-w-md mx-auto pb-36" x-data="{
    loading: false,
    sortByDistance() {
        if (!navigator.geolocation) {
            alert('Geolocation tidak didukung oleh browser Anda.');
            return;
        }
        this.loading = true;
        navigator.geolocation.getCurrentPosition(
            (position) => {
                $wire.sortByDistance(position.coords.latitude, position.coords.longitude)
                    .then(() => { this.loading = false; });
            },
            (error) => {
                this.loading = false;
                alert('Gagal mendapatkan lokasi GPS: ' + error.message);
            },
            {
                enableHighAccuracy: true,
                timeout: 5000,
                maximumAge: 0
            }
        );
    }
}">
    {{-- Banner offline: operator harus tahu koneksi putus, bukan spinner selamanya --}}
    <div wire:offline class="fixed top-0 inset-x-0 z-[60] bg-red-600 text-white text-center text-sm font-semibold py-2.5 shadow-md">
        Koneksi internet terputus. Periksa sinyal, lalu coba lagi.
    </div>

    {{-- Header --}}
    <div class="mb-6 bg-white p-4 border-b border-slate-200 shadow-sm">
        <div class="flex justify-between items-start">
            <div>
                <h1 class="text-xl font-bold text-slate-900">Trip #{{ $trip->trip_number_of_day }}</h1>
                {{-- Area AWAL trip (tetap, catatan asal) --}}
                @if ($trip->starting_cluster_id)
                    <p class="text-slate-600 text-sm mt-1">
                        Area awal: <span class="text-amber-700 font-semibold">{{ $trip->startingCluster->name }}</span>
                    </p>
                @else
                    <p class="text-slate-600 text-sm mt-1">Area awal: <span class="text-slate-500 font-semibold">Semua Kios</span></p>
                @endif
                {{-- Area yang sedang DILIHAT — beda dari area awal saat operator menyeberang.
                     Tanpa baris ini, "lihat area lain" jadi perpindahan senyap. --}}
                @if ($viewClusterId !== $trip->starting_cluster_id)
                    <p class="text-xs text-sky-700 font-semibold mt-0.5">
                        👀 Sedang lihat: {{ $viewedAreaName }}
                    </p>
                @endif
            </div>
            <div class="text-right">
                <span class="inline-flex items-center rounded-md bg-green-50 px-2 py-1 text-xs font-medium text-green-700 ring-1 ring-inset ring-green-600/20">Aktif</span>
                <p class="text-[10px] text-slate-500 mt-1">{{ $trip->started_at->format('H:i') }}</p>
            </div>
        </div>
    </div>

    {{-- Daftar Kios Aktual --}}
    <div class="px-4 space-y-3">
        {{-- ══ LIHAT AREA LAIN ══
             Dodol masih sisa setelah area awal habis? Lanjut ke kedai area lain
             TANPA mengakhiri trip — kalau akhiri lalu mulai trip baru, komisi &
             data pengantaran terpecah jadi dua trip.
             Pemilihan area di sini EKSPLISIT, tidak lewat "Urutkan Jarak": cakupan
             GPS baru ~12%, jadi jarak tak pernah bisa jadi mekanisme lintas area. --}}
        <div class="mb-2">
            <button type="button" wire:click="{{ $isAreaPickerOpen ? 'closeAreaPicker' : 'openAreaPicker' }}"
                    wire:loading.attr="disabled" wire:target="openAreaPicker,closeAreaPicker"
                    class="w-full flex items-center justify-between gap-2 rounded-lg border border-sky-300 bg-sky-50 px-3 py-2.5 min-h-[44px] text-sm font-semibold text-sky-800 active:bg-sky-100">
                <span class="flex items-center gap-1.5">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/>
                    </svg>
                    Lihat Area Lain
                </span>
                <span class="text-xs font-normal text-sky-700">{{ $viewedAreaName }}</span>
            </button>

            @if ($isAreaPickerOpen)
                <div class="mt-2 rounded-xl border border-sky-200 bg-white p-2 shadow-sm space-y-1.5">
                    <p class="px-2 pt-1 text-xs text-slate-500">
                        Pilih area — trip <span class="font-semibold">tetap Trip #{{ $trip->trip_number_of_day }}</span>, tidak diakhiri.
                    </p>

                    <button type="button" wire:click="switchArea(null)"
                            class="w-full text-left rounded-lg px-3 py-2.5 min-h-[44px] text-sm border
                                   {{ $viewClusterId === null ? 'border-sky-500 bg-sky-50 font-bold text-sky-900' : 'border-slate-200 hover:bg-slate-50' }}">
                        Semua Area
                        <span class="block text-xs font-normal text-slate-500">Semua kedai, dikelompokkan per area</span>
                    </button>

                    @foreach ($availableAreas as $area)
                        <button type="button" wire:click="switchArea({{ $area->id }})"
                                class="w-full text-left rounded-lg px-3 py-2.5 min-h-[44px] text-sm border
                                       {{ $viewClusterId === (int) $area->id ? 'border-sky-500 bg-sky-50 font-bold text-sky-900' : 'border-slate-200 hover:bg-slate-50' }}">
                            {{ $area->name }}
                            @if ((int) $area->id === (int) $startingClusterId)
                                <span class="ml-1 text-[10px] font-semibold text-amber-700">· area awal</span>
                            @endif
                            <span class="block text-xs font-normal text-slate-500">{{ $area->kiosks_count }} kios aktif</span>
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Judul di baris SENDIRI, dua tombol aksi di baris berikutnya dengan lebar
             sama rata. Sebelumnya ketiganya berebut satu baris (~388px di HP 420px)
             sehingga "DAFTAR KUNJUNGAN", "+ Kios Baru", dan "Urutkan Jarak" sama-sama
             membungkus jadi dua baris dan terlihat gepeng. Label dijaga `whitespace-nowrap`
             supaya tak pernah membungkus lagi. --}}
        <h2 class="text-sm font-bold text-slate-900 uppercase tracking-wider mb-2">Daftar Kunjungan</h2>

        <div class="grid grid-cols-2 gap-2 mb-2">
            <a href="{{ route('operator.kiosks.create') }}" wire:navigate
               class="inline-flex items-center justify-center gap-1.5 rounded-lg px-3 py-2.5 min-h-[44px] text-sm font-semibold whitespace-nowrap shadow-sm ring-1 ring-inset bg-slate-900 text-white ring-slate-950 hover:bg-slate-800 transition-colors">
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                <span>Kios Baru</span>
            </a>
            <button type="button"
                    @click="sortByDistance()"
                    :disabled="loading"
                    class="inline-flex items-center justify-center gap-1.5 rounded-lg px-3 py-2.5 min-h-[44px] text-sm font-semibold whitespace-nowrap shadow-sm ring-1 ring-inset transition-colors
                           {{ $sortedByDistance ? 'bg-amber-600 text-white ring-amber-600 hover:bg-amber-700' : 'bg-amber-50 text-amber-700 ring-amber-600/20 hover:bg-amber-100 active:bg-amber-200' }}
                           disabled:opacity-50">
                <svg x-show="!loading" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
                <svg x-show="loading" class="animate-spin h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" style="display: none;">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                {{-- Label aktif dipendekkan dari "Terurut Jarak Terdekat" (22 huruf, pasti
                     membungkus di setengah lebar layar) jadi "Terdekat Dulu". --}}
                <span x-text="loading ? 'Mencari…' : '{{ $sortedByDistance ? 'Terdekat Dulu' : 'Urutkan Jarak' }}'">Urutkan Jarak</span>
            </button>
        </div>

        {{-- Cari kios: daftar dimuat {{ $displayLimit }} kartu per batch demi ringan di
             HP. Sisa kios dijangkau lewat tombol "Muat lebih banyak" di bawah daftar
             (atau kotak cari ini) — TIDAK dipotong diam-diam. --}}
        <div class="relative">
            <input type="search" wire:model.live.debounce.400ms="search"
                   placeholder="Cari nama kios…"
                   class="w-full rounded-lg border-slate-300 focus:border-amber-500 focus:ring-amber-500 text-sm pl-9">
            <svg class="h-4 w-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z"/>
            </svg>
            <div wire:loading.flex wire:target="search" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400">
                <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
            </div>
        </div>
        @if ($totalMatched > count($kiosks))
            <p class="text-xs text-slate-500 -mt-1">
                Menampilkan {{ count($kiosks) }} dari {{ $totalMatched }} kios{{ trim($search) !== '' ? ' yang cocok' : '' }}.
                Tekan <span class="font-semibold text-amber-700">Muat lebih banyak</span> di bawah untuk sisanya.
            </p>
        @endif

        @foreach ($kioskGroups as $group)
            {{-- Judul pemisah per area / per grup GPS: batas area harus TERLIHAT,
                 bukan cuma terurut diam-diam. --}}
            @if ($showGroupLabels)
                <div wire:key="grp-{{ $group['key'] }}" class="flex items-center gap-2 pt-3 first:pt-0">
                    <span class="h-px flex-1 bg-slate-200"></span>
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-600 whitespace-nowrap">
                        {{ $group['label'] }}
                    </span>
                    <span class="text-[10px] text-slate-400 whitespace-nowrap">{{ $group['note'] }}</span>
                    <span class="h-px flex-1 bg-slate-200"></span>
                </div>
            @endif

        @foreach ($group['kiosks'] as $kiosk)
            @php
                $isVisited = in_array($kiosk->id, $visitedKioskIds);
                $hasPending = in_array($kiosk->id, $pendingKioskIds);
                // Mika titipan berjalan — dari subquery berkorelasi di query utama
                // (withCardAggregates), 0 query per kartu.
                $titipanMika = (int) ($kiosk->pending_titipan_mika ?? 0);
                $isBooking = in_array($kiosk->id, $bookingKioskIds);
            @endphp
            <div wire:key="kiosk-{{ $kiosk->id }}"
                 @if(!$isVisited) wire:click="openVisitModal({{ $kiosk->id }})" @endif
                 class="bg-white rounded-xl border p-4 flex items-center justify-between shadow-sm transition-colors
                        {{ $isVisited ? 'opacity-50 cursor-default border-slate-200' : 'cursor-pointer border-slate-200 active:bg-slate-50 hover:border-amber-300' }}">
                <div>
                    <p class="font-bold text-slate-900">{{ $kiosk->name }}</p>
                    <p class="text-sm text-slate-500">
                        {{ $kiosk->owner_name ?? '—' }}
                        {{-- Label AREA di baris pemilik (tak menambah tinggi kartu).
                             Hanya saat daftar menjangkau lebih dari satu area — di trip
                             satu-area ia cuma mengulang header. --}}
                        @if($showAreaOnCard && $kiosk->cluster)
                            <span class="text-slate-400">·</span>
                            <span class="text-slate-500 font-medium">{{ $kiosk->cluster->name }}</span>
                        @endif
                    </p>
                    <div class="flex flex-wrap gap-2 mt-1">
                        @if($isVisited)
                            <span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full">✓ Dikunjungi</span>
                        @else
                            <span class="text-xs bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full">Belum</span>
                        @endif
                        @if(in_array($kiosk->id, $correctedKioskIds))
                            <span class="text-xs bg-slate-100 text-slate-600 px-2 py-0.5 rounded-full">✎ Dikoreksi</span>
                        @endif

                        {{-- TITIPAN — tiga kondisi, satu badge (tak menambah tinggi kartu).
                             Angka mika menggantikan badge lama "Ada Titipan": tempat sama,
                             informasi jauh lebih berguna ("4 mika" vs "ada"). --}}
                        @if($kiosk->is_cash_only)
                            <span class="text-xs bg-yellow-100 text-yellow-800 px-2 py-0.5 rounded-full font-semibold">💵 Cash Only</span>
                        @elseif($hasPending && $titipanMika > 0)
                            <span class="text-xs bg-green-100 text-green-800 px-2 py-0.5 rounded-full font-semibold">{{ $titipanMika }} mika</span>
                        @elseif($hasPending)
                            {{-- Titipan ada tapi qty 0 (data lama) — jangan diam. --}}
                            <span class="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full">Ada Titipan</span>
                        @elseif($isBooking)
                            <span class="text-xs bg-sky-100 text-sky-700 px-2 py-0.5 rounded-full font-semibold">📌 Belum pernah dititip</span>
                        @endif

                        {{-- Smart Kios Flags --}}
                        @php $flags = $kioskFlags[$kiosk->id] ?? []; @endphp
                        @if(in_array('urgent', $flags))
                            <span class="text-xs bg-red-100 text-red-700 px-2 py-0.5 rounded-full font-bold">🔴 URGENT</span>
                        @elseif(in_array('warning', $flags))
                            <span class="text-xs bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full">⚠️ Hampir Telat</span>
                        @endif
                        @if(in_array('fast_mover', $flags))
                            <span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full">⚡ Laku Cepat</span>
                        @endif
                        @if(in_array('slow_mover', $flags))
                            <span class="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full">🐢 Laku Lambat</span>
                        @endif
                        @if(in_array('new', $flags))
                            <span class="text-xs bg-purple-100 text-purple-700 px-2 py-0.5 rounded-full">⭐ Kios Baru</span>
                        @endif
                    </div>

                    @if(isset($lastOperatorPerKiosk[$kiosk->id]))
                        <p class="text-xs text-slate-400 mt-1">
                            👤 {{ $lastOperatorPerKiosk[$kiosk->id]['name'] }} • {{ $lastOperatorPerKiosk[$kiosk->id]['date'] }}
                        </p>
                    @endif
                </div>
                @if(!$isVisited)
                    <svg class="h-5 w-5 text-slate-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                @else
                    {{-- Tombol koreksi angka — hanya untuk kios yang sudah dikunjungi --}}
                    <button type="button" wire:click="openCorrectionModal({{ $kiosk->id }})"
                            wire:loading.attr="disabled" wire:target="openCorrectionModal"
                            class="shrink-0 inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-amber-700 bg-amber-50 ring-1 ring-inset ring-amber-600/20 hover:bg-amber-100 active:bg-amber-200">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                        </svg>
                        Koreksi
                    </button>
                @endif
            </div>
        @endforeach
        @endforeach

        @if ($kiosks->isEmpty())
            <div class="text-center py-8 text-slate-400">
                @if (trim($search) !== '')
                    <p>Tidak ada kios cocok dengan "{{ $search }}".</p>
                    <p class="text-sm mt-1">Coba kata kunci lain atau kosongkan pencarian.</p>
                @else
                    <p>Belum ada kios di area ini.</p>
                    <p class="text-sm mt-1">Coba <span class="font-semibold text-sky-700">Lihat Area Lain</span>, atau tambah kios via menu Kios Baru.</p>
                @endif
            </div>
        @endif

        {{-- Ekor daftar: daftar dimuat per batch, JANGAN pernah berhenti diam-diam.
             Dulu daftar terpotong keras di kios ke-50 tanpa penanda apa pun, sehingga
             operator mengira area itu memang habis (bug "berhenti di Bilal 3"). --}}
        @if ($totalMatched > count($kiosks))
            <button type="button" wire:click="loadMoreKiosks"
                    wire:loading.attr="disabled" wire:target="loadMoreKiosks"
                    class="w-full rounded-xl border border-dashed border-amber-400 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800 active:bg-amber-100 disabled:opacity-50">
                <span wire:loading.remove wire:target="loadMoreKiosks">
                    Muat lebih banyak ({{ $totalMatched - count($kiosks) }} kios lagi)
                </span>
                <span wire:loading wire:target="loadMoreKiosks">Memuat…</span>
            </button>
        @elseif (count($kiosks) > 0 && trim($search) === '')
            <p class="text-center text-xs text-slate-400 py-2">
                — Semua {{ $totalMatched }} kios di daftar ini sudah tampil —
            </p>
        @endif
    </div>

    {{-- Tombol Akhiri Trip — selalu terlihat di dasar layar (bottom nav
         disembunyikan di halaman ini, jadi tidak pernah bertabrakan) --}}
    <div class="fixed bottom-0 left-0 right-0 p-4 pb-[max(1rem,env(safe-area-inset-bottom))] bg-white border-t border-slate-200 max-w-md mx-auto z-30 shadow-md space-y-2.5">
        {{-- Jual Cash Cepat: penjualan ke pembeli walk-in (bukan kios terdaftar) --}}
        <button type="button" wire:click="openWalkInModal" wire:loading.attr="disabled" wire:target="openWalkInModal"
                class="w-full bg-amber-500 text-white font-bold text-lg py-3 rounded-xl shadow-sm active:bg-amber-600 flex items-center justify-center gap-2">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <span>Jual Cash Cepat</span>
        </button>
        <button type="button" wire:click="openEndTripModal" wire:loading.attr="disabled" wire:target="openEndTripModal" class="w-full bg-red-600 text-white font-bold text-lg py-3 rounded-xl shadow-sm active:bg-red-700">
            <span wire:loading.remove wire:target="openEndTripModal">Akhiri Trip</span>
            <span wire:loading wire:target="openEndTripModal">Memuat...</span>
        </button>
    </div>

    {{-- MODAL JUAL CASH CEPAT (WALK-IN) --}}
    @if($isWalkInModalOpen)
    <div class="fixed inset-0 z-50 flex items-end justify-center sm:items-center">
        <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity" wire:click="closeWalkInModal"></div>

        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full max-w-md max-h-[90vh] overflow-y-auto shadow-2xl">
            <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                <div>
                    <h3 class="font-bold text-lg text-slate-900">Jual Cash Cepat</h3>
                    <p class="text-xs text-slate-500">Penjualan langsung ke pembeli (bukan kios titipan).</p>
                </div>
                <button type="button" wire:click="closeWalkInModal" class="p-2 bg-slate-100 text-slate-500 rounded-full hover:bg-slate-200">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <div class="px-5 py-5 space-y-4">
                @error('general')
                    <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-3 py-2">{{ $message }}</div>
                @enderror

                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Jumlah Mika</label>
                    <div class="flex items-center justify-center gap-3">
                        <button type="button" x-on:click="$wire.walkInMika = Math.max(0, ($wire.walkInMika || 0) - 1)"
                                class="w-12 h-12 rounded-lg bg-slate-100 border border-slate-200 text-slate-600 text-xl font-bold flex items-center justify-center active:bg-slate-200">-</button>
                        <input type="number" onfocus="this.select()" min="1" wire:model.live="walkInMika"
                               class="w-24 text-center text-2xl font-bold rounded-lg border-slate-300 focus:border-amber-500 focus:ring-amber-500">
                        <button type="button" x-on:click="$wire.walkInMika = ($wire.walkInMika || 0) + 1"
                                class="w-12 h-12 rounded-lg bg-slate-100 border border-slate-200 text-slate-600 text-xl font-bold flex items-center justify-center active:bg-slate-200">+</button>
                    </div>
                    @error('walkInMika')
                        <p class="text-sm text-red-600 mt-1.5 text-center">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Total otomatis: mika × 15 biji × Rp 800 --}}
                <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-center">
                    <p class="text-xs text-amber-700 font-semibold uppercase tracking-wide">Total Harga (Lunas Cash)</p>
                    <p class="text-2xl font-bold text-amber-800 mt-1">
                        Rp {{ number_format(max(0, (int) $walkInMika) * 15 * 800, 0, ',', '.') }}
                    </p>
                    <p class="text-[11px] text-amber-600 mt-1">{{ max(0, (int) $walkInMika) }} mika × 15 biji × Rp 800</p>
                </div>
            </div>

            <div class="px-5 py-4 border-t border-slate-100 space-y-2.5">
                <button type="button" wire:click="saveWalkInCash" wire:loading.attr="disabled" wire:target="saveWalkInCash"
                        @disabled((int) $walkInMika < 1)
                        class="w-full bg-amber-500 text-white font-bold py-3 rounded-xl shadow-sm active:bg-amber-600 disabled:opacity-50">
                    <span wire:loading.remove wire:target="saveWalkInCash">Simpan Penjualan</span>
                    <span wire:loading wire:target="saveWalkInCash">Menyimpan...</span>
                </button>
                <button type="button" wire:click="closeWalkInModal" class="w-full bg-slate-100 text-slate-700 font-bold py-3 rounded-xl border border-slate-200 active:bg-slate-200">
                    Batal
                </button>
            </div>
        </div>
    </div>
    @endif

    {{-- MODAL KOREKSI ANGKA KUNJUNGAN --}}
    @if($isCorrectionModalOpen)
    <div class="fixed inset-0 z-50 flex items-end justify-center sm:items-center">
        <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity" wire:click="closeCorrectionModal"></div>

        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full max-w-md max-h-[90vh] overflow-y-auto shadow-2xl">
            <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                <div>
                    <h3 class="font-bold text-lg text-slate-900">Koreksi Kunjungan</h3>
                    <p class="text-xs text-slate-500">{{ $correctionKioskName ?? 'Kios' }} — perbaiki angka yang salah.</p>
                </div>
                <button type="button" wire:click="closeCorrectionModal" class="p-2 bg-slate-100 text-slate-500 rounded-full hover:bg-slate-200">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <div class="px-5 py-5 space-y-4">
                @error('correction')
                    <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-3 py-2">{{ $message }}</div>
                @enderror

                {{-- Drop mika (kalau visit ini mendrop titipan/cash) --}}
                @if($correctionHasDrop)
                    <div class="bg-white border border-slate-200 rounded-xl p-4">
                        <label class="block text-sm font-bold text-slate-900 mb-2">Jumlah Mika</label>
                        <input type="number" onfocus="this.select()" min="0" wire:model="dropBaru"
                               class="w-full rounded-lg border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 text-center text-2xl font-bold">
                    </div>
                @endif

                {{-- Penagihan titipan lama (kalau visit ini menagih titipan) --}}
                @if($correctionHasSettle)
                    <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 space-y-4">
                        <span class="text-xs font-bold text-amber-800 uppercase tracking-wider">Penagihan Titipan</span>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-slate-700 mb-1">Sisa Bagus (Biji)</label>
                                <input type="number" onfocus="this.select()" min="0" wire:model="returnFresh"
                                       class="w-full rounded-lg border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 text-center text-lg font-bold">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-700 mb-1">Dodol Sisa/Basi (Biji)</label>
                                <input type="number" onfocus="this.select()" min="0" wire:model="returnExpired"
                                       class="w-full rounded-lg border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 text-center text-lg font-bold text-red-600">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Uang Diterima (Rp)</label>
                            <input type="number" onfocus="this.select()" min="0" wire:model="uangDiterima"
                                   class="w-full rounded-lg border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 text-xl font-bold text-green-700 bg-white">
                            @error('uangDiterima')
                                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                @endif
            </div>

            <div class="px-5 py-4 border-t border-slate-100 space-y-2.5">
                <button type="button" wire:click="submitCorrection" wire:loading.attr="disabled" wire:target="submitCorrection"
                        class="w-full bg-amber-500 text-white font-bold py-3 rounded-xl shadow-sm active:bg-amber-600 disabled:opacity-50">
                    <span wire:loading.remove wire:target="submitCorrection">Simpan Koreksi</span>
                    <span wire:loading wire:target="submitCorrection">Menyimpan...</span>
                </button>
                <button type="button" wire:click="closeCorrectionModal" class="w-full bg-slate-100 text-slate-700 font-bold py-3 rounded-xl border border-slate-200 active:bg-slate-200">
                    Batal
                </button>
            </div>
        </div>
    </div>
    @endif

    {{-- MODAL AKHIRI TRIP --}}
    @if($isEndTripModalOpen)
    <div class="fixed inset-0 z-50 flex items-end justify-center sm:items-center">
        <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity" wire:click="closeEndTripModal"></div>

        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full max-w-md max-h-[90vh] overflow-y-auto shadow-2xl">
            <div class="px-5 py-4 border-b border-slate-100">
                <h3 class="font-bold text-lg text-slate-900">Akhiri Trip</h3>
                <p class="text-xs text-slate-500">Pastikan ringkasan di bawah sudah sesuai.</p>
            </div>

            <div class="p-5 space-y-5">
                {{-- Ringkasan Trip --}}
                <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-slate-600">Total Kios Dikunjungi</span>
                        <span class="font-bold text-slate-900">{{ $tripSummary['kios_visited'] }}</span>
                    </div>
                    <div class="flex justify-between pl-3 text-xs text-slate-500">
                        <span>— Kios Lama</span>
                        <span>{{ $tripSummary['kios_lama'] }}</span>
                    </div>
                    <div class="flex justify-between pl-3 text-xs text-slate-500">
                        <span>— Kios Baru</span>
                        <span>{{ $tripSummary['kios_baru'] }}</span>
                    </div>
                    <div class="border-t border-slate-200 my-2"></div>
                    <div class="flex justify-between">
                        <span class="text-slate-600">Mika Dibawa</span>
                        <span class="font-bold text-slate-900">{{ $tripSummary['qty_carried'] }} mika</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-600">Total Titip</span>
                        <span class="font-bold text-slate-900">{{ $tripSummary['total_mika_drop'] }} mika</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-600">Sisa di Motor</span>
                        <span class="font-bold {{ $tripSummary['total_mika_sisa'] >= 0 ? 'text-green-700' : 'text-red-600' }}">{{ $tripSummary['total_mika_sisa'] }} mika</span>
                    </div>
                    <div class="border-t border-slate-200 my-2"></div>
                    <div class="flex justify-between">
                        <span class="text-slate-600 font-medium">Mika Terjual (biji ÷ 15)</span>
                        <span class="font-bold text-slate-900">{{ number_format($tripSummary['mika_terjual'], 2, ',', '.') }} mika</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-600 font-medium">Mika Kios Baru</span>
                        <span class="font-bold text-slate-900">{{ number_format($tripSummary['mika_kios_baru'], 2, ',', '.') }} mika</span>
                    </div>
                    @if(($tripSummary['total_mika_cash'] ?? 0) > 0)
                    <div class="flex justify-between">
                        <span class="text-slate-600 font-medium">Mika Cash</span>
                        <span class="font-bold text-slate-900">{{ $tripSummary['total_mika_cash'] }} mika (Rp {{ number_format($tripSummary['total_amount_cash'], 0, ',', '.') }})</span>
                    </div>
                    @endif
                    <div class="border-t border-slate-200 my-2"></div>
                    <div class="flex justify-between text-green-700 font-semibold">
                        <span>Omset (Cash Diterima)</span>
                        <span>Rp {{ number_format($tripSummary['total_uang_diterima'], 0, ',', '.') }}</span>
                    </div>
                </div>

                {{-- Pilih Alasan --}}
                <div>
                    <label class="block text-sm font-bold text-slate-900 mb-2">Alasan Mengakhiri</label>
                    <div class="space-y-2">
                        @foreach (['stock_habis' => 'Stok Habis', 'target_done' => 'Target Tercapai', 'sakit' => 'Sakit', 'urgent_personal' => 'Keperluan Mendadak', 'other' => 'Lainnya'] as $value => $label)
                            <label class="flex items-center gap-3 p-3 rounded-lg border cursor-pointer {{ $endReason === $value ? 'border-amber-500 bg-amber-50' : 'border-slate-200' }}">
                                <input type="radio" wire:model.live="endReason" value="{{ $value }}" class="text-amber-600 focus:ring-amber-500">
                                <span class="text-sm font-medium text-slate-800">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('endReason')
                        <p class="text-xs text-red-600 mt-2">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="sticky bottom-0 bg-white border-t border-slate-100 p-4 grid grid-cols-2 gap-3">
                <button type="button" wire:click="closeEndTripModal" class="w-full bg-slate-100 text-slate-700 font-bold py-3 rounded-xl border border-slate-200 active:bg-slate-200">
                    Batal
                </button>
                <button type="button" wire:click="confirmEndTrip" wire:loading.attr="disabled" wire:target="confirmEndTrip" @disabled($endReason === '') class="w-full bg-red-600 text-white font-bold py-3 rounded-xl shadow-sm active:bg-red-700 disabled:opacity-50">
                    <span wire:loading.remove wire:target="confirmEndTrip">Konfirmasi Akhiri Trip</span>
                    <span wire:loading wire:target="confirmEndTrip">Mengakhiri...</span>
                </button>
            </div>
        </div>
    </div>
    @endif
    {{-- MODAL KUNJUNGAN (SETTLE & DROP) --}}
    @if($isVisitModalOpen && $selectedKiosk)
    <div class="fixed inset-0 z-50 flex items-end justify-center sm:items-center">
        <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity" wire:click="closeVisitModal"></div>

        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full max-w-md max-h-[90vh] overflow-y-auto shadow-2xl flex flex-col">
            
            <div class="sticky top-0 bg-white px-5 py-4 border-b border-slate-100 flex justify-between items-center z-10">
                <div>
                    <div class="flex items-center gap-2">
                        <h3 class="font-bold text-lg text-slate-900">{{ $selectedKiosk->name }}</h3>
                        @if($isCashOnly)
                            <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-bold text-emerald-700 ring-1 ring-emerald-200">CASH ONLY</span>
                        @endif
                    </div>
                    <p class="text-xs text-slate-500">Form Serah Terima</p>
                </div>
                <button wire:click="closeVisitModal" class="p-2 bg-slate-100 text-slate-500 rounded-full hover:bg-slate-200">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>

            <div class="p-5 space-y-5">
                {{-- Foto kios di puncak modal + tambah/ganti foto oleh operator dari lapangan.
                     Kompres di browser (canvas) → upload → simpan (saveKioskPhoto), lalu foto
                     baru tampil. Gate multi-tenant di server (saveKioskPhoto). --}}
                <div x-data="kioskPhotoUpload()" class="mb-3 -mx-4 -mt-2">
                    @if($selectedKiosk->photo_url)
                        <div class="relative">
                            <img src="{{ $selectedKiosk->photo_url }}"
                                 alt="Foto {{ $selectedKiosk->name }}"
                                 class="w-full max-h-36 object-cover">
                            <div class="absolute bottom-2 right-2 flex items-center gap-1.5">
                                <button type="button" @click="$refs.fotoKamera.click()" :disabled="busy"
                                        class="inline-flex items-center gap-1.5 rounded-lg bg-slate-900/80 text-white text-xs font-semibold px-3 py-1.5 shadow active:bg-slate-900 disabled:opacity-60">
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    </svg>
                                    <span x-text="busy ? 'Menyimpan…' : 'Foto Ulang'">Foto Ulang</span>
                                </button>
                                <button type="button" @click="$refs.fotoGaleri.click()" :disabled="busy"
                                        class="inline-flex items-center gap-1.5 rounded-lg bg-slate-900/80 text-white text-xs font-semibold px-3 py-1.5 shadow active:bg-slate-900 disabled:opacity-60">
                                    🖼️ Galeri
                                </button>
                            </div>
                        </div>
                    @else
                        <div class="px-4 grid grid-cols-2 gap-2">
                            <button type="button" @click="$refs.fotoKamera.click()" :disabled="busy"
                                    class="flex items-center justify-center gap-2 rounded-xl border-2 border-dashed border-slate-300 bg-slate-50 py-6 text-sm font-semibold text-slate-600 active:bg-slate-100 disabled:opacity-60">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/>
                                </svg>
                                <span x-text="busy ? 'Menyimpan…' : '📷 Ambil Foto'">📷 Ambil Foto</span>
                            </button>
                            <button type="button" @click="$refs.fotoGaleri.click()" :disabled="busy"
                                    class="flex items-center justify-center gap-2 rounded-xl border-2 border-dashed border-slate-300 bg-slate-50 py-6 text-sm font-semibold text-slate-600 active:bg-slate-100 disabled:opacity-60">
                                <span x-text="busy ? 'Menyimpan…' : '🖼️ Dari Galeri'">🖼️ Dari Galeri</span>
                            </button>
                        </div>
                    @endif

                    {{-- DUA input terpisah, SENGAJA (bukan satu input + capture): atribut
                         `capture` MEMAKSA kamera & menghilangkan pilihan galeri di HP. Kamera
                         = capture="environment" (kamera belakang, langsung jepret tanpa keluar
                         app); Galeri = input polos (perilaku LAMA). Desktop mengabaikan
                         capture → dua-duanya file picker biasa. Keduanya ke handle() yang sama. --}}
                    <input type="file" accept="image/*" capture="environment" x-ref="fotoKamera" class="hidden" x-on:change="handle($event)">
                    <input type="file" accept="image/*" x-ref="fotoGaleri" class="hidden" x-on:change="handle($event)">

                    @error('kioskPhoto')
                        <p class="text-xs text-red-600 mt-1.5 px-4">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Error umum --}}
                @error('general')
                    <div class="bg-red-50 border border-red-200 rounded-lg p-3 text-sm text-red-700">{{ $message }}</div>
                @enderror

                {{-- STATUS KEDAI (sekali lihat paham) — ikut kenyataan, bukan label kaku. --}}
                @if($pendingDelivery)
                    <div class="flex items-center gap-2 rounded-xl bg-amber-50 border border-amber-200 px-3 py-2 text-sm">
                        <span class="text-base">🏪</span>
                        <span class="text-amber-800">Kedai ini: <b>ada titipan {{ $pendingDelivery->qty_delivered }} mika</b> ({{ $pendingDelivery->qty_delivered * 15 }} biji).</span>
                    </div>
                @elseif($isCashOnly)
                    <div class="flex items-center gap-2 rounded-xl bg-emerald-50 border border-emerald-200 px-3 py-2 text-sm">
                        <span class="text-base">💵</span>
                        <span class="text-emerald-800">Kedai ini: <b>cash-only</b> (tak ada titipan).</span>
                    </div>
                @else
                    <div class="flex items-center gap-2 rounded-xl bg-slate-50 border border-slate-200 px-3 py-2 text-sm">
                        <span class="text-base">📦</span>
                        <span class="text-slate-700">Kedai ini: <b>belum ada titipan berjalan</b>.</span>
                    </div>
                @endif

                {{-- CATATAN KEDAI (menonjol) — karakteristik/kebiasaan kedai, biar operator tahu
                     tanpa harus ingat. Diisi owner ATAU operator. --}}
                @if(trim((string) $selectedKiosk->store_note) !== '')
                    <div class="rounded-xl bg-blue-50 border border-blue-200 px-3 py-2.5 text-sm">
                        <p class="text-[11px] font-bold text-blue-700 uppercase tracking-wider mb-0.5">📌 Catatan Kedai</p>
                        <p class="text-blue-900 whitespace-pre-line">{{ $selectedKiosk->store_note }}</p>
                    </div>
                @endif

                {{-- TAHAP 3: Piutang lama (Settlement pending) + terima pembayaran (pelunasan) --}}
                @if($piutangLama > 0)
                    <div class="bg-red-50 border border-red-200 rounded-lg p-3 text-sm">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-red-700">💰 <span class="font-semibold">Piutang lama: Rp {{ number_format($piutangLama, 0, ',', '.') }}</span></span>
                            @unless($payingPiutang)
                                <button type="button" wire:click="startPiutangPayment"
                                        class="text-xs font-bold text-white bg-red-600 px-3 py-1.5 rounded-lg active:bg-red-700 shrink-0">Terima Pembayaran</button>
                            @endunless
                        </div>
                        @if($piutangJanji !== '')
                            <p class="text-xs text-red-600 mt-1">{{ $piutangJanji }}</p>
                        @endif
                        @if($payingPiutang)
                            <div class="mt-3 flex items-center gap-2">
                                <input type="number" onfocus="this.select()" wire:model="piutangBayar" min="1" max="{{ $piutangLama }}"
                                       placeholder="Jumlah diterima (Rp)"
                                       class="flex-1 rounded-lg border-slate-300 text-sm py-2 focus:border-red-500 focus:ring-red-500">
                                <button type="button" wire:click="terimaPembayaranPiutang" wire:loading.attr="disabled" wire:target="terimaPembayaranPiutang"
                                        class="text-xs font-bold text-white bg-green-600 px-3 py-2 rounded-lg active:bg-green-700 shrink-0">Konfirmasi</button>
                            </div>
                            <p class="text-[11px] text-slate-400 mt-1">Pelunasan utang lama — tidak masuk omzet/komisi hari ini.</p>
                        @endif
                        @error('piutang')
                            <p class="text-xs text-red-600 mt-1 font-medium">{{ $message }}</p>
                        @enderror
                    </div>
                @endif

                @if($stopMode !== '')
                    {{-- ============ ALUR: HENTIKAN KEDAI (stop titipan) ============ --}}
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-bold text-red-700">⛔ Hentikan Kedai {{ $selectedKiosk->name }}</span>
                        <button type="button" wire:click="cancelStop"
                            class="text-xs font-semibold text-slate-500 bg-slate-100 px-3 py-2 min-h-[36px] rounded-lg active:bg-slate-200">
                            ← Batal
                        </button>
                    </div>

                    @if($stopMode === 'pick')
                        {{-- Pilih cara stop --}}
                        <p class="text-xs font-bold text-slate-500 uppercase tracking-wider">Pilih cara menghentikan kedai</p>
                        <div class="space-y-3">
                            @if($pendingDelivery)
                                <button type="button" wire:click="chooseStopMode('tagih')"
                                    class="w-full text-left p-4 rounded-xl bg-white border-2 border-amber-300 active:bg-amber-50">
                                    <span class="font-bold text-base text-slate-900 block">🧾 Stop + Tagih Terakhir</span>
                                    <span class="text-xs text-slate-500 mt-0.5 block">Pemilik berhenti baik-baik — catat dodol yang laku, sisa &amp; dodol basi, lalu uang terakhir. Kedai berhenti permanen.</span>
                                </button>
                            @endif
                            <button type="button" wire:click="chooseStopMode('tanpa_tagih')"
                                class="w-full text-left p-4 rounded-xl bg-white border-2 border-red-300 active:bg-red-50">
                                <span class="font-bold text-base text-red-700 block">🚫 Stop Tanpa Tagih</span>
                                <span class="text-xs text-slate-500 mt-0.5 block">Kedai kabur / tak bisa ditagih — sisa dodol dicatat sebagai kerugian (uang Rp 0), lalu kedai berhenti.</span>
                            </button>
                        </div>
                    @else
                        {{-- Form stop (a) tagih / (b) tanpa tagih --}}
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-bold text-red-700">
                                {{ $stopMode === 'tagih' ? '🧾 Stop + Tagih Terakhir' : '🚫 Stop Tanpa Tagih' }}
                            </span>
                            <button type="button" wire:click="backToStopPick"
                                class="text-xs font-semibold text-slate-500 bg-slate-100 px-3 py-2 min-h-[36px] rounded-lg active:bg-slate-200">
                                ← Ganti Cara
                            </button>
                        </div>

                        {{-- (a) Form tagih terakhir — pola sama Tagih Saja --}}
                        @if($stopMode === 'tagih' && $pendingDelivery)
                            <div class="bg-amber-50 border border-amber-200 rounded-xl p-4">
                                <div class="flex justify-between items-center mb-4">
                                    <span class="text-xs font-bold text-amber-800 uppercase tracking-wider">Titipan Sebelumnya</span>
                                    <span class="text-sm font-bold text-slate-900">{{ $pendingDelivery->qty_delivered }} Mika ({{ $pendingDelivery->qty_delivered * 15 }} Biji)</span>
                                </div>
                                <div class="grid grid-cols-2 gap-4 mb-4">
                                    <div>
                                        <label class="block text-xs font-medium text-slate-700 mb-1">Sisa Bagus (Biji)</label>
                                        <input type="number" onfocus="this.select()" wire:model.live="returnFresh" class="w-full rounded-lg border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 text-center text-lg font-bold" min="0">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-slate-700 mb-1">Dodol Sisa/Basi (Biji)<br><span class="text-[10px] font-normal text-slate-400">(dodol rusak/basi yang diretur)</span></label>
                                        <input type="number" onfocus="this.select()" wire:model.live="returnExpired" class="w-full rounded-lg border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 text-center text-lg font-bold text-red-600" min="0">
                                    </div>
                                </div>
                                <div class="border-t border-amber-200 pt-3">
                                    <div class="flex justify-between items-center text-sm mb-1">
                                        <span class="text-slate-600">Terjual:</span>
                                        <span class="font-bold text-slate-900">{{ $terjual }} Biji</span>
                                    </div>
                                    <div class="flex justify-between items-center text-sm">
                                        <span class="text-slate-600">Total Tagihan:</span>
                                        <span class="font-bold text-slate-900">Rp {{ number_format($tagihan, 0, ',', '.') }}</span>
                                    </div>
                                </div>
                                <div class="mt-4">
                                    <label class="block text-xs font-bold text-slate-700 mb-1">Uang Diterima (Rp)</label>
                                    <input type="number" onfocus="this.select()" wire:model="uangDiterima" class="w-full rounded-lg border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 text-xl font-bold text-green-700 bg-white" min="0">
                                    @error('uangDiterima')
                                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>
                        @endif

                        {{-- (b) Ringkasan kerugian --}}
                        @if($stopMode === 'tanpa_tagih')
                            <div class="bg-red-50 border border-red-200 rounded-xl p-3 text-sm text-red-700">
                                @if($pendingDelivery)
                                    ⚠️ Titipan {{ $pendingDelivery->qty_delivered }} mika ({{ $pendingDelivery->qty_delivered * 15 }} biji) akan dicatat sebagai <b>kerugian</b> — semua dianggap dodol basi/hilang, uang Rp 0. Pembukuan tetap jujur (titipan tidak menggantung).
                                @else
                                    Kedai ini tidak punya titipan aktif — langsung dihentikan tanpa kerugian.
                                @endif
                            </div>
                        @endif

                        {{-- Alasan berhenti (wajib) --}}
                        <div>
                            <p class="text-sm font-bold text-red-700 mb-2">Alasan berhenti:</p>
                            <div class="space-y-2">
                                @foreach(\App\Models\Kiosk::STOP_REASONS as $val => $label)
                                    <label class="flex items-center gap-2 p-3 rounded-xl border cursor-pointer {{ $stopReason === $val ? 'border-red-400 bg-red-50' : 'border-slate-200' }}">
                                        <input type="radio" wire:model.live="stopReason" value="{{ $val }}" class="sr-only">
                                        <span class="text-sm">{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                            @error('stopReason')
                                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        @error('general')
                            <div class="bg-red-50 border border-red-200 rounded-lg p-3 text-sm text-red-700">{{ $message }}</div>
                        @enderror

                        {{-- Gerbang konfirmasi tegas (poin 3) --}}
                        @if(! $stopConfirming)
                            <button type="button" wire:click="requestStopConfirm"
                                class="w-full py-3 rounded-xl bg-red-600 text-white text-sm font-bold active:bg-red-700">
                                Lanjut Hentikan Kedai
                            </button>
                        @else
                            <div class="bg-red-50 border-2 border-red-300 rounded-xl p-4 space-y-3">
                                <p class="text-sm font-bold text-red-700">⚠️ Kedai ini akan DIHENTIKAN permanen &amp; hilang dari daftar trip. Angka yang kamu isi FINAL — tidak bisa dikoreksi dari sini lagi. Yakin?</p>
                                <div class="flex gap-2">
                                    <button type="button" wire:click="$set('stopConfirming', false)"
                                        class="flex-1 py-2 rounded-xl border border-slate-200 text-sm text-slate-600 active:bg-slate-50">
                                        Batal
                                    </button>
                                    <button type="button" wire:click="executeStop" wire:loading.attr="disabled" wire:target="executeStop"
                                        class="flex-1 py-2 rounded-xl bg-red-600 text-white text-sm font-bold active:bg-red-700 disabled:opacity-60">
                                        <span wire:loading.remove wire:target="executeStop">Ya, Hentikan Permanen</span>
                                        <span wire:loading wire:target="executeStop">Memproses...</span>
                                    </button>
                                </div>
                            </div>
                        @endif
                    @endif
                @elseif(is_null($chosenAction))
                    {{-- ============ LAYAR 1: PILIH AKSI ============ --}}

                    {{-- Navigasi hanya di layar pertama (foto sudah tampil di puncak modal) --}}
                    @if($selectedKiosk->latitude && $selectedKiosk->longitude)
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

                    @if($pendingDelivery)
                        <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 flex justify-between items-center text-sm">
                            <span class="font-medium text-amber-800">Titipan sebelumnya</span>
                            <span class="font-bold text-slate-900">{{ $pendingDelivery->qty_delivered }} mika ({{ $pendingDelivery->qty_delivered * 15 }} biji)</span>
                        </div>

                        {{-- Banner Titipan Tertunda: titipan ini sebelumnya ditandai "belum bisa bayar". --}}
                        @if($tertundaMika > 0)
                            <div class="bg-amber-50 border border-amber-300 rounded-lg p-3 text-sm text-amber-800">
                                ⏳ <span class="font-semibold">Titipan belum tertagih: {{ $tertundaMika }} mika.</span>
                                @if($tertundaJanji !== '')
                                    <br><span class="text-amber-700">{{ $tertundaJanji }}</span>
                                @endif
                            </div>
                        @endif

                        <p class="text-xs font-bold text-slate-500 uppercase tracking-wider">Mau ngapain di kedai ini?</p>
                        <div class="space-y-3">
                            {{-- 1 — siklus normal --}}
                            <button type="button" wire:click="chooseAction('tagih_titip')"
                                class="w-full text-left p-4 rounded-xl bg-amber-600 text-white shadow-sm active:bg-amber-700">
                                <span class="font-bold text-base block">💰 Tagih + Titip Ulang</span>
                                <span class="text-xs text-amber-100 mt-0.5 block">Siklus normal. Tagih yang laku (jatah dikurangi BS), titip lagi sejumlah jatah.</span>
                            </button>
                            {{-- 2 — naruh cash ekstra, tidak nagih --}}
                            <button type="button" wire:click="chooseAction('titip_cash')"
                                class="w-full text-left p-4 rounded-xl bg-white border-2 border-emerald-300 active:bg-emerald-50">
                                <span class="font-bold text-base text-emerald-700 block">💵 Titip Cash</span>
                                <span class="text-xs text-slate-500 mt-0.5 block">Naruh dodol ekstra dibayar tunai. Titipan lama &amp; sisanya tetap, ditagih nanti pas siklus normal.</span>
                            </button>
                            {{-- 3 — lewati, cuma catat sisa --}}
                            <button type="button" wire:click="chooseAction('cek')"
                                class="w-full text-left p-4 rounded-xl bg-white border-2 border-slate-200 active:bg-slate-50">
                                <span class="font-bold text-base text-slate-900 block">👀 Lewati / Belum Habis</span>
                                <span class="text-xs text-slate-500 mt-0.5 block">Kedai belum habis, belum mau ditambah. Cuma catat sisa, lanjut.</span>
                            </button>
                            {{-- 4 — GANTI KE CASH (baru): tagih titipan terakhir, tak titip lagi, kedai
                                 tetap didatangi tapi mode cash. "Ganti" (lanjut) ≠ "Hentikan" (berhenti). --}}
                            <button type="button" wire:click="chooseAction('ganti_cash')"
                                class="w-full text-left p-4 rounded-xl bg-white border-2 border-sky-300 active:bg-sky-50">
                                <span class="font-bold text-base text-sky-700 block">🔄 Ganti ke Cash (baru)</span>
                                <span class="text-xs text-slate-500 mt-0.5 block">Ambil bayaran titipan terakhir. Kedai tetap didatangi, tapi mulai sekarang beli tunai (tidak dititip lagi).</span>
                            </button>
                        </div>

                        {{-- Hentikan Kedai (stop titipan) — satu pintu, pilih cara di layar berikutnya --}}
                        <button type="button" wire:click="startStop"
                            class="w-full mt-4 text-center p-3 rounded-xl border border-red-200 bg-red-50 text-sm font-bold text-red-700 active:bg-red-100">
                            ⛔ Hentikan Kedai Ini
                        </button>
                    @else
                        <p class="text-xs font-bold text-slate-500 uppercase tracking-wider">Mau ngapain di kedai ini?</p>
                        <div class="space-y-3">
                            {{-- 1 — Titip Cash (yang biasa dipakai kedai cash-only) --}}
                            <button type="button" wire:click="chooseAction('titip_cash')"
                                class="w-full text-left p-4 rounded-xl bg-emerald-600 text-white shadow-sm active:bg-emerald-700">
                                <span class="font-bold text-base block">💵 Titip Cash</span>
                                <span class="text-xs text-emerald-100 mt-0.5 block">Naruh dodol dibayar tunai sekarang. Yang biasa dipakai kedai cash-only.</span>
                            </button>
                            {{-- 2 — lewati / cek saja --}}
                            <button type="button" wire:click="chooseAction('cek')"
                                class="w-full text-left p-4 rounded-xl bg-white border-2 border-slate-200 active:bg-slate-50">
                                <span class="font-bold text-base text-slate-900 block">👀 Lewati / Cek Saja</span>
                                <span class="text-xs text-slate-500 mt-0.5 block">Catat kunjungan tanpa transaksi (kedai tutup, cuma mampir, dll).</span>
                            </button>
                            {{-- 3 — MULAI TITIPAN (jarang): cash-only tiba-tiba mau dititipin --}}
                            <button type="button" wire:click="chooseAction('mulai_titipan')"
                                class="w-full text-left p-4 rounded-xl bg-white border-2 border-amber-300 active:bg-amber-50">
                                <span class="font-bold text-base text-amber-700 block">🔄 Mulai Titipan (konsinyasi)</span>
                                <span class="text-xs text-slate-500 mt-0.5 block">Kedai ini mau mulai dititip dodol (sebelumnya cash aja). Isi berapa mika dititip + jatah seterusnya.</span>
                            </button>
                        </div>

                        {{-- Hentikan Kedai (stop titipan) — satu pintu, pilih cara di layar berikutnya --}}
                        <button type="button" wire:click="startStop"
                            class="w-full mt-4 text-center p-3 rounded-xl border border-red-200 bg-red-50 text-sm font-bold text-red-700 active:bg-red-100">
                            ⛔ Hentikan Kedai Ini
                        </button>
                    @endif
                @else
                    {{-- ============ LAYAR 2: FORM SESUAI AKSI (3-AKSI) ============ --}}
                    @php
                        // Jatah kios & status blokir 2-langkah (dipakai UI + footer). Blokir
                        // hanya untuk AKSI 1 (titip konsinyasi) saat jatah sudah ada & titip
                        // != jatah tanpa centang "Ubah jatah". Kios baru (jatah<1) bebas.
                        $jatah = (int) ($selectedKiosk->default_qty_mika ?? 0);
                        $blokirTitip = $chosenAction === 'tagih_titip'
                            && $jatah >= 1 && ! $ubahJatah && (int) $dropBaru !== $jatah;
                    @endphp

                    <div class="flex items-center justify-between">
                        <span class="text-sm font-bold text-amber-700">
                            @switch($chosenAction)
                                @case('tagih_titip') 💰 Tagih + Titip Ulang @break
                                @case('titip_cash') 💵 Titip Cash @break
                                @case('cek') 👀 Lewati / Belum Habis @break
                                @case('ganti_cash') 🔄 Ganti ke Cash (baru) @break
                                @case('mulai_titipan') 🔄 Mulai Titipan (konsinyasi) @break
                            @endswitch
                        </span>
                        <button type="button" wire:click="backToActionPicker"
                            class="text-xs font-semibold text-slate-500 bg-slate-100 px-3 py-2 min-h-[36px] rounded-lg active:bg-slate-200">
                            ← Ganti Aksi
                        </button>
                    </div>

                    {{-- Catatan aksi (abu-abu kecil, teks owner) --}}
                    <p class="text-xs text-slate-400 -mt-3">
                        @switch($chosenAction)
                            @case('tagih_titip') Siklus normal. Tagih yang laku (jatah dikurangi BS), titip lagi sejumlah jatah. @break
                            @case('titip_cash') Naruh dodol ekstra dibayar tunai. Titipan lama &amp; sisanya tetap, ditagih nanti pas siklus normal. @break
                            @case('cek') Kedai belum habis, belum mau ditambah. Cuma catat sisa, lanjut. @break
                            @case('ganti_cash') Ambil bayaran titipan terakhir. Kedai tetap didatangi, tapi mulai sekarang beli tunai (tidak dititip lagi). @break
                            @case('mulai_titipan') Kedai ini mau mulai dititip dodol (sebelumnya cash aja). Isi berapa mika dititip + jatah seterusnya. @break
                        @endswitch
                    </p>

                    {{-- AREA TAGIHAN — AKSI 1 (tagih + titip ulang) & GANTI KE CASH
                         (tagih titipan TERAKHIR, tak titip lagi). Form settle-nya sama. --}}
                    @if(in_array($chosenAction, ['tagih_titip', 'ganti_cash'], true) && $pendingDelivery)
                        @if($chosenAction === 'ganti_cash')
                            <div class="rounded-xl bg-sky-50 border border-sky-200 px-3 py-2.5 text-sm text-sky-800">
                                🔄 Tagih titipan <b>TERAKHIR</b> — hitung BS, ambil uang. Setelah ini kedai <b>TIDAK dititip lagi</b>, tapi <b>tetap aktif &amp; tetap dikunjungi</b> (mode beli tunai).
                            </div>
                        @endif
                        <div class="bg-amber-50 border border-amber-200 rounded-xl p-4">
                            <div class="flex justify-between items-center mb-4">
                                <span class="text-xs font-bold text-amber-800 uppercase tracking-wider">Titipan Sebelumnya</span>
                                <span class="text-sm font-bold text-slate-900">{{ $pendingDelivery->qty_delivered }} Mika ({{ $pendingDelivery->qty_delivered * 15 }} Biji)</span>
                            </div>

                            <div class="grid grid-cols-2 gap-4 mb-4">
                                <div>
                                    <label class="block text-xs font-medium text-slate-700 mb-1">Sisa Bagus (Biji)</label>
                                    <input type="number" onfocus="this.select()" wire:model.live="returnFresh" class="w-full rounded-lg border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 text-center text-lg font-bold" min="0">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-700 mb-1">Barang Sisa / BS (Biji)<br><span class="text-[10px] font-normal text-slate-400">(dodol rusak/basi yang diretur)</span></label>
                                    <input type="number" onfocus="this.select()" wire:model.live="returnExpired" class="w-full rounded-lg border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 text-center text-lg font-bold text-red-600" min="0">
                                </div>
                            </div>

                            <div class="border-t border-amber-200 pt-3">
                                <div class="flex justify-between items-center text-sm mb-1">
                                    <span class="text-slate-600">Laku (Terjual):</span>
                                    <span class="font-bold text-slate-900">{{ $terjual }} Biji</span>
                                </div>
                                <div class="flex justify-between items-center text-sm">
                                    <span class="text-slate-600">Total Tagihan:</span>
                                    <span class="font-bold text-slate-900">Rp {{ number_format($tagihan, 0, ',', '.') }}</span>
                                </div>
                            </div>

                            <div class="mt-4">
                                <label class="block text-xs font-bold text-slate-700 mb-1">Uang Diterima (Rp)</label>
                                <input type="number" onfocus="this.select()" wire:model.live.debounce.500ms="uangDiterima" class="w-full rounded-lg border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 text-xl font-bold text-green-700 bg-white" min="0">
                                @error('uangDiterima')
                                    <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                                @enderror
                            </div>

                            {{-- TAHAP 3: bayar KURANG dari tagihan → sisa jadi piutang. Catat janji bayar. --}}
                            @if((int) $tagihan > 0 && (int) $uangDiterima < (int) $tagihan)
                                <div class="mt-3 bg-red-50 border border-red-200 rounded-lg p-3">
                                    <p class="text-xs font-medium text-red-700 mb-2">
                                        Kurang Rp {{ number_format((int) $tagihan - (int) $uangDiterima, 0, ',', '.') }} → jadi piutang.
                                    </p>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">Janji Bayar (kapan?)</label>
                                    <input type="text" wire:model="janjiBayar" maxlength="120"
                                           placeholder="cth: nanti sore / besok / pengantaran berikutnya"
                                           class="w-full rounded-lg border-slate-300 text-sm py-2 px-3 focus:border-red-500 focus:ring-red-500">
                                </div>
                            @endif
                        </div>
                    @endif

                    {{-- AREA TITIP ULANG (AKSI 1: tagih + titip ulang). SATU field jumlah. --}}
                    @if($chosenAction === 'tagih_titip')
                        <div class="bg-white border border-slate-200 rounded-xl p-4">
                            <label class="block text-sm font-bold text-slate-900 mb-2">Titip Ulang (Mika)</label>
                            <div class="flex items-center gap-3">
                                <button type="button" wire:click="decrementDrop" class="w-12 h-12 rounded-lg bg-slate-100 border border-slate-200 text-slate-600 text-xl font-bold flex items-center justify-center active:bg-slate-200">-</button>

                                <input type="number" onfocus="this.select()" id="dropBaru" wire:model.live.debounce.500ms="dropBaru" class="flex-1 rounded-lg border shadow-sm text-center text-2xl font-bold {{ $blokirTitip ? 'border-red-400 focus:border-red-500 focus:ring-red-500 text-red-600' : 'border-slate-300 focus:border-amber-500 focus:ring-amber-500' }}" min="0">

                                <button type="button" wire:click="incrementDrop" class="w-12 h-12 rounded-lg bg-slate-100 border border-slate-200 text-slate-600 text-xl font-bold flex items-center justify-center active:bg-slate-200">+</button>
                            </div>

                            @if($jatah >= 1)
                                <p class="mt-2 text-xs text-slate-400">Jatah biasa kedai ini {{ $jatah }} mika.</p>
                            @endif

                            @if($blokirTitip)
                                {{-- BLOKIR 2-LANGKAH: titip != jatah tanpa "Ubah jatah" → tolak + arahkan.
                                     Server tetap menolak walau tombol diakali (persistVisitFromState). --}}
                                <div class="mt-3 rounded-xl border-2 border-red-300 bg-red-50 p-3">
                                    <p class="text-sm font-bold text-red-800 mb-1">
                                        🚫 Titip harus sama dengan jatah ({{ $jatah }}).
                                    </p>
                                    <ul class="text-xs text-red-700 space-y-0.5 list-disc list-inside">
                                        <li>Mau ubah jatah kedai ini? Centang <b>Ubah jatah permanen</b> di bawah.</li>
                                        <li>Mau naruh ekstra dibayar tunai? <b>← Ganti Aksi</b> → pilih <b>Titip Cash</b>.</li>
                                        <li>Salah ketik? Betulkan jadi {{ $jatah }}.</li>
                                    </ul>
                                </div>
                            @endif
                        </div>

                        {{-- CHECKBOX UBAH JATAH satu-angka (AKSI 1). Angka titip di atas = jatah
                             baru saat dicentang — TIDAK ada field kedua. --}}
                        <div class="bg-white border border-slate-200 rounded-xl p-4">
                            <label class="flex items-start gap-3 cursor-pointer">
                                <input type="checkbox" wire:model.live="ubahJatah" class="mt-0.5 rounded text-amber-600">
                                <span class="text-sm font-medium text-slate-700">
                                    Ubah jatah permanen kedai ini (naik/turun)
                                    <br><span class="text-xs font-normal text-slate-400">Ganti jatah kedai ini buat seterusnya. Angka titip di atas jadi jatah baru.</span>
                                </span>
                            </label>
                            @if($ubahJatah)
                                <div class="mt-2 pl-8 space-y-1">
                                    <p class="text-xs text-amber-600">Jatah kedai ini jadi <b>{{ (int) $dropBaru ?: '?' }} mika</b> seterusnya.</p>
                                    <p class="text-xs text-slate-500">ℹ️ Tagihan hari ini tetap dari titipan LAMA — tak terpengaruh jatah baru.</p>
                                </div>
                            @endif
                        </div>
                    @endif

                    {{-- MULAI TITIPAN: kedai cash-only/baru mulai konsinyasi. Dua field —
                         berapa mika DITITIP sekarang + JATAH seterusnya (boleh beda). --}}
                    @if($chosenAction === 'mulai_titipan')
                        <div class="bg-white border border-slate-200 rounded-xl p-4">
                            <label class="block text-sm font-bold text-slate-900 mb-2">Mika Dititip Sekarang</label>
                            <div class="flex items-center gap-3">
                                <button type="button" wire:click="decrementDrop" class="w-12 h-12 rounded-lg bg-slate-100 border border-slate-200 text-slate-600 text-xl font-bold flex items-center justify-center active:bg-slate-200">-</button>
                                <input type="number" onfocus="this.select()" wire:model.live.debounce.500ms="dropBaru" class="flex-1 rounded-lg border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 text-center text-2xl font-bold" min="1">
                                <button type="button" wire:click="incrementDrop" class="w-12 h-12 rounded-lg bg-slate-100 border border-slate-200 text-slate-600 text-xl font-bold flex items-center justify-center active:bg-slate-200">+</button>
                            </div>
                            <p class="mt-2 text-xs text-slate-400">Berapa mika yang kamu titipkan ke kedai ini sekarang.</p>
                        </div>
                        <div class="bg-white border border-slate-200 rounded-xl p-4">
                            <label class="block text-sm font-bold text-slate-900 mb-2">Jatah Seterusnya (Mika)</label>
                            <input type="number" onfocus="this.select()" wire:model.live.debounce.500ms="jatahMulai" min="1"
                                   class="w-full rounded-lg border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 text-center text-2xl font-bold">
                            <p class="mt-2 text-xs text-slate-400">Jatah tetap kedai ini ke depan. Kunjungan berikutnya otomatis muncul "Tagih + Titip Ulang".</p>
                        </div>
                    @endif

                    {{-- AKSI 2 — TITIP CASH: naruh ekstra dibayar tunai, BEBAS berapa saja
                         (tak terikat jatah). TIDAK nagih titipan lama. Jatah TIDAK berubah
                         (ubah-jatah tak relevan → tak ada). BS (biji tak layak jual) dicatat:
                         kedai bayar (biji ditaruh − BS), BS jadi kerugian owner. --}}
                    @if($chosenAction === 'titip_cash')
                        @php
                            $bijiDitaruhCash = (int) $dropBaru * 15;
                            $bsCash = max(0, (int) $qtyBsCash);
                            $bsLebihCash = $bsCash > $bijiDitaruhCash;
                            $bijiBayarCash = max(0, $bijiDitaruhCash - $bsCash);
                            $cashDiterima = $bijiBayarCash * 800;
                        @endphp
                        @if($pendingDelivery)
                            <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-sm text-amber-800">
                                ℹ️ Titipan lama <b>{{ $pendingDelivery->qty_delivered }} mika</b> &amp; sisanya <b>TIDAK disentuh</b> — ditagih nanti pas siklus normal.
                            </div>
                        @endif
                        <div class="bg-white border border-slate-200 rounded-xl p-4">
                            <label class="block text-sm font-bold text-slate-900 mb-2">Naruh Cash (Mika)</label>
                            <div class="flex items-center gap-3">
                                <button type="button" wire:click="decrementDrop" class="w-12 h-12 rounded-lg bg-slate-100 border border-slate-200 text-slate-600 text-xl font-bold flex items-center justify-center active:bg-slate-200">-</button>
                                <input type="number" onfocus="this.select()" wire:model.live.debounce.500ms="dropBaru" class="flex-1 rounded-lg border-slate-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 text-center text-2xl font-bold" min="0">
                                <button type="button" wire:click="incrementDrop" class="w-12 h-12 rounded-lg bg-slate-100 border border-slate-200 text-slate-600 text-xl font-bold flex items-center justify-center active:bg-slate-200">+</button>
                            </div>
                            <p class="mt-2 text-xs text-slate-400">Bebas naruh berapa saja (tak terikat jatah). Jatah kedai TETAP {{ $jatah }} mika.</p>
                        </div>

                        {{-- BS (biji tak layak jual) — SAMA seperti AKSI 1 (satuan BIJI). Kedai
                             TIDAK bayar BS; BS jadi kerugian owner (laporan kerugian yang sama). --}}
                        <div class="bg-white border border-slate-200 rounded-xl p-4">
                            <label class="block text-sm font-bold text-slate-900 mb-2">
                                Barang Sisa / BS (Biji)
                                <br><span class="text-[10px] font-normal text-slate-400">(biji rusak/tak layak jual — kedai tidak bayar, jadi kerugian owner)</span>
                            </label>
                            <input type="number" onfocus="this.select()" wire:model.live.debounce.500ms="qtyBsCash" min="0"
                                   class="w-full rounded-lg border-slate-300 shadow-sm focus:border-red-500 focus:ring-red-500 text-center text-lg font-bold text-red-600">
                            @if($bsLebihCash)
                                <p class="mt-2 text-xs font-semibold text-red-700">BS tidak boleh melebihi biji yang ditaruh ({{ $bijiDitaruhCash }}).</p>
                            @endif
                        </div>

                        {{-- Ringkasan cash yang diterima (read-only) — operator tahu ambil uang
                             berapa sebelum simpan. --}}
                        @if((int) $dropBaru > 0)
                            <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-4 text-sm space-y-1">
                                <div class="flex justify-between">
                                    <span class="text-slate-600">Biji ditaruh</span>
                                    <span class="font-bold text-slate-900">{{ $bijiDitaruhCash }} biji</span>
                                </div>
                                @if($bsCash > 0)
                                    <div class="flex justify-between">
                                        <span class="text-slate-600">BS (kerugian owner)</span>
                                        <span class="font-bold text-red-600">− {{ min($bsCash, $bijiDitaruhCash) }} biji</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-slate-600">Dibayar kedai</span>
                                        <span class="font-bold text-slate-900">{{ $bijiBayarCash }} biji</span>
                                    </div>
                                @endif
                                <div class="border-t border-emerald-200 pt-1 flex justify-between items-center">
                                    <span class="font-semibold text-emerald-800">Cash Diterima</span>
                                    <span class="text-lg font-bold text-emerald-800">Rp {{ number_format($cashDiterima, 0, ',', '.') }}</span>
                                </div>
                                <p class="text-[11px] text-emerald-700">
                                    ({{ $bijiDitaruhCash }}@if($bsCash > 0) − {{ min($bsCash, $bijiDitaruhCash) }}@endif) biji × Rp 800. Jatah kedai TETAP {{ $jatah }} mika.
                                </p>
                            </div>
                        @endif
                    @endif

                    {{-- AKSI 3 — LEWATI / BELUM HABIS: alasan + sisa biji + catatan. --}}
                    @if($chosenAction === 'cek')
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-bold text-slate-700 mb-2">Alasan Kunjungan</label>
                                @php
                                    // "Belum Bisa Bayar (tunda)" hanya relevan kalau ada titipan untuk ditunda.
                                    $cekReasons = [
                                        'kios_tutup' => '🔒 Kios Tutup',
                                        'pemilik_minta_tunggu' => '⏳ Minta Tunggu',
                                        'dodol_masih_banyak' => '📦 Dodol Masih Ada',
                                    ];
                                    if ($pendingDelivery) {
                                        $cekReasons['belum_bisa_bayar'] = '💰 Belum Bisa Bayar';
                                    }
                                @endphp
                                <div class="grid grid-cols-2 gap-2">
                                    @foreach($cekReasons as $val => $label)
                                        <label class="flex items-center gap-2 p-3 rounded-xl border cursor-pointer {{ $alasanCheck === $val ? 'border-amber-400 bg-amber-50' : 'border-slate-200' }}">
                                            <input type="radio" wire:model.live="alasanCheck" value="{{ $val }}" class="sr-only">
                                            <span class="text-sm font-medium">{{ $label }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>

                            {{-- Janji bayar — hanya saat alasan "belum bisa bayar". Disimpan ke
                                 KioskVisit.notes. Titipan TETAP pending (tidak di-settle). --}}
                            @if($alasanCheck === 'belum_bisa_bayar')
                                <div>
                                    <label class="block text-sm font-bold text-slate-700 mb-2">Janji Bayar (kapan?)</label>
                                    <input type="text" wire:model="janjiBayar" maxlength="120"
                                           placeholder="cth: nanti sore / besok / pengantaran berikutnya"
                                           class="w-full rounded-xl border-slate-300 text-sm py-3 px-3 focus:border-amber-500 focus:ring-amber-500">
                                    <p class="text-xs text-slate-400 mt-1">Titipan TETAP tercatat (belum lunas) — ditagih di kunjungan berikutnya.</p>
                                </div>
                            @endif

                            <div>
                                <label class="block text-sm font-bold text-slate-700 mb-2">Sisa Dodol di Kedai (Biji)</label>
                                <input type="number" onfocus="this.select()" wire:model="sisaBiji" class="w-full rounded-xl border-slate-300 text-center text-xl font-bold py-3" min="0" placeholder="0">
                                <p class="text-xs text-slate-400 mt-1">Isi 0 kalau tidak tahu atau kedai tutup. Data ini dipakai buat perbaikan sistem ke depan.</p>
                            </div>

                            {{-- Catatan bebas (opsional) — disimpan ke KioskVisit.notes.
                                 Disembunyikan saat "belum bisa bayar" (di atas sudah ada field
                                 Janji Bayar yang pakai properti sama). --}}
                            @unless($alasanCheck === 'belum_bisa_bayar')
                                <div>
                                    <label class="block text-sm font-bold text-slate-700 mb-2">Catatan (opsional)</label>
                                    <input type="text" wire:model="janjiBayar" maxlength="120"
                                           placeholder="cth: pemilik lagi sepi, minggu depan mampir lagi"
                                           class="w-full rounded-xl border-slate-300 text-sm py-3 px-3 focus:border-amber-500 focus:ring-amber-500">
                                </div>
                            @endunless

                            {{-- AKSI 3 (Lewati) TIDAK punya ubah-jatah lagi (owner 12 Juli 2026):
                                 lewati = tak menaruh apa pun → ubah-jatah tak relevan. Ubah jatah
                                 kini HANYA di AKSI 1 (Tagih + Titip Ulang). --}}
                        </div>
                    @endif

                    {{-- OPSI KHUSUS (collapsed): Dodol Sisa redistribusi --}}
                    @if($chosenAction === 'tagih_titip')
                        <div x-data="{ openOpsi: false }" class="border border-slate-200 rounded-xl">
                            <button type="button" @click="openOpsi = !openOpsi"
                                class="w-full flex items-center justify-between p-3 min-h-[44px] text-sm font-semibold text-slate-600">
                                <span>⚙️ Opsi Khusus</span>
                                <svg class="h-4 w-4 transition-transform" :class="openOpsi ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </button>
                            <div x-show="openOpsi" x-collapse class="px-3 pb-3 space-y-3" style="display:none">
                                {{-- SKENARIO 7: Dodol Sisa redistribusi — hanya saat titip --}}
                                @if($chosenAction === 'tagih_titip')
                                    <div>
                                        <label class="flex items-center gap-3 cursor-pointer p-3 rounded-xl border border-slate-200">
                                            <input type="checkbox" wire:model.live="adaBsRedistribusi" class="rounded text-amber-600">
                                            <span class="text-sm font-medium text-slate-700">Ada mika Dodol Sisa dari kios lain ikut dititip</span>
                                        </label>
                                        @if($adaBsRedistribusi)
                                            <div class="mt-2">
                                                <label class="text-xs text-slate-500">Jumlah mika Dodol Sisa yang ikut (mika)</label>
                                                <input type="number" onfocus="this.select()" wire:model.live="qtyBsMika" min="1" class="w-full rounded-xl border-slate-300 text-center font-bold py-2 mt-1">
                                                <p class="text-xs text-slate-400 mt-1">
                                                    Total titip ke kios ini: {{ (int) $dropBaru + (int) $qtyBsMika }} mika
                                                    ({{ (int) $dropBaru }} baru + {{ (int) $qtyBsMika }} Dodol Sisa)
                                                </p>
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif
                @endif
            </div>

            @if(!is_null($chosenAction))
            @php
                // Jatah + blokir 2-langkah (dihitung ulang di footer — variabel scope PHP
                // @php di atas tidak bocor ke sini).
                $jatahFooter = (int) ($selectedKiosk->default_qty_mika ?? 0);
                // BLOKIR 2-LANGKAH: HANYA AKSI 1 (tagih_titip). Ganti ke Cash & Mulai
                // Titipan aksi mandiri → tak kena blokir ini (blokir tetap utuh di AKSI 1).
                $blokirFooter = $chosenAction === 'tagih_titip'
                    && $jatahFooter >= 1 && ! $ubahJatah && (int) $dropBaru !== $jatahFooter;

                // Aksi yang WAJIB ada mika (drop/cash > 0) sebelum bisa disimpan:
                // tagih_titip (titip ulang), titip_cash (cash), mulai_titipan (mika dititip).
                // Ganti ke Cash (drop=0, settle) & Lewati (drop=0) TIDAK butuh mika.
                $needDrop = in_array($chosenAction, ['tagih_titip', 'titip_cash', 'mulai_titipan'], true) && (int) $dropBaru < 1;
                // MULAI TITIPAN: jatah seterusnya wajib >=1.
                $needJatahMulai = $chosenAction === 'mulai_titipan' && (int) $jatahMulai < 1;
                // AKSI 2: BS (biji) tak boleh melebihi biji yang ditaruh (dropBaru × 15).
                $bsOverCash = $chosenAction === 'titip_cash' && (int) $qtyBsCash > (int) $dropBaru * 15;
                $saveDisabled = $needDrop || $needJatahMulai || $blokirFooter || $bsOverCash;
                $saveLabel = $blokirFooter
                    ? 'Betulkan titip = jatah dulu'
                    : ($bsOverCash
                        ? 'Betulkan BS dulu'
                        : (($chosenAction === 'tagih_titip' && (int) $dropBaru < 1)
                            ? 'Isi jumlah titipan dulu'
                            : ($needDrop
                                ? ($chosenAction === 'titip_cash' ? 'Isi jumlah cash dulu' : 'Isi jumlah mika dulu')
                                : ($needJatahMulai
                                    ? 'Isi jatah seterusnya dulu'
                                    : 'Simpan Kunjungan'))));
            @endphp
            <div class="sticky bottom-0 bg-white border-t border-slate-100 p-4 z-10">
                @if($chosenAction === 'tagih_titip' && (int) $dropBaru < 1)
                    <p class="text-xs text-slate-500 mb-2 text-center">
                        Kalau cuma ambil bayaran tanpa titip baru, pakai <span class="font-semibold text-red-700">⛔ Hentikan Kedai</span>.
                    </p>
                @endif
                <button type="button" wire:click="saveVisit" wire:loading.attr="disabled" wire:target="saveVisit"
                    @disabled($saveDisabled)
                    class="w-full bg-slate-900 text-white font-bold text-lg py-3 rounded-xl shadow-sm active:bg-slate-800 disabled:opacity-60">
                    <span wire:loading.remove wire:target="saveVisit">{{ $saveLabel }}</span>
                    <span wire:loading wire:target="saveVisit">Menyimpan...</span>
                </button>
            </div>
            @endif
            
        </div>
    </div>
    @endif

    {{-- Kompres foto di browser SEBELUM upload — satu sumber dengan create-kiosk:
         resources/js/kiosk-photo.js (window.kompresFotoKios; canvas, sisi terpanjang
         1600px, JPEG 0.8, tanpa library). Foto kamera HP 8–17MB dikecilkan dulu supaya
         tak kena plafon & cepat terkirim di sinyal lapangan. Kalau kompres tak bisa
         (mis. HEIC di Android), file asli dikirim & server yang konversi/kecilkan.
         Setelah upload selesai → saveKioskPhoto() menyimpan. --}}
    <script>
        function kioskPhotoUpload() {
            return {
                busy: false,
                async handle(e) {
                    const file = e.target.files[0];
                    if (!file) return;
                    this.busy = true;
                    e.target.value = ''; // boleh pilih file yang SAMA lagi setelah ini
                    const out = await window.kompresFotoKios(file);
                    this.$wire.upload('kioskPhoto', out,
                        () => { this.$wire.saveKioskPhoto().then(() => { this.busy = false; }); },
                        () => { this.busy = false; },
                    );
                },
            };
        }
    </script>
</div>