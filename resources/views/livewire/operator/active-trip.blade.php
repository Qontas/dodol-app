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
                @if ($trip->starting_cluster_id)
                    <p class="text-slate-600 text-sm mt-1">
                        Area: <span class="text-amber-700 font-semibold">{{ $trip->startingCluster->name }}</span>
                    </p>
                @else
                    <p class="text-slate-600 text-sm mt-1">Area: <span class="text-slate-500 font-semibold">Semua Kios</span></p>
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
        <div class="flex justify-between items-center mb-2 gap-2">
            <h2 class="text-sm font-bold text-slate-900 uppercase tracking-wider">Daftar Kunjungan</h2>
            <div class="flex items-center gap-2">
                <a href="{{ route('operator.kiosks.create') }}" wire:navigate
                   class="inline-flex items-center gap-1 rounded-lg px-3 py-2.5 min-h-[44px] text-sm font-semibold shadow-sm ring-1 ring-inset bg-slate-900 text-white ring-slate-950 hover:bg-slate-800 transition-colors">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    <span>+ Kios Baru</span>
                </a>
                <button type="button"
                        @click="sortByDistance()"
                        :disabled="loading"
                        class="inline-flex items-center gap-1.5 rounded-lg px-3 py-2.5 min-h-[44px] text-sm font-semibold shadow-sm ring-1 ring-inset transition-colors
                               {{ $sortedByDistance ? 'bg-amber-600 text-white ring-amber-600 hover:bg-amber-700' : 'bg-amber-50 text-amber-700 ring-amber-600/20 hover:bg-amber-100 active:bg-amber-200' }}
                               disabled:opacity-50">
                    <svg x-show="!loading" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    <svg x-show="loading" class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24" style="display: none;">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span x-text="loading ? 'Mencari...' : '{{ $sortedByDistance ? 'Terurut Jarak Terdekat' : 'Urutkan Jarak' }}'">Urutkan Jarak</span>
                </button>
            </div>
        </div>

        {{-- Cari kios: daftar dibatasi {{ $displayLimit }} kartu demi ringan di HP;
             operator tetap bisa menjangkau kios mana pun lewat kotak ini. --}}
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
                @if (trim($search) === '')
                    Ketik nama untuk cari kios lain, atau tekan <span class="font-semibold text-amber-700">Urutkan Jarak</span> untuk yang terdekat.
                @endif
            </p>
        @endif

        @forelse ($kiosks as $kiosk)
            @php
                $isVisited = in_array($kiosk->id, $visitedKioskIds);
                $hasPending = in_array($kiosk->id, $pendingKioskIds);
            @endphp
            <div wire:key="kiosk-{{ $kiosk->id }}"
                 @if(!$isVisited) wire:click="openVisitModal({{ $kiosk->id }})" @endif
                 class="bg-white rounded-xl border p-4 flex items-center justify-between shadow-sm transition-colors
                        {{ $isVisited ? 'opacity-50 cursor-default border-slate-200' : 'cursor-pointer border-slate-200 active:bg-slate-50 hover:border-amber-300' }}">
                <div>
                    <p class="font-bold text-slate-900">{{ $kiosk->name }}</p>
                    <p class="text-sm text-slate-500">{{ $kiosk->owner_name ?? '—' }}</p>
                    <div class="flex flex-wrap gap-2 mt-1">
                        @if($isVisited)
                            <span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full">✓ Dikunjungi</span>
                        @else
                            <span class="text-xs bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full">Belum</span>
                        @endif
                        @if(in_array($kiosk->id, $correctedKioskIds))
                            <span class="text-xs bg-slate-100 text-slate-600 px-2 py-0.5 rounded-full">✎ Dikoreksi</span>
                        @endif
                        @if($hasPending)
                            <span class="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full">Ada Titipan</span>
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
        @empty
            <div class="text-center py-8 text-slate-400">
                @if (trim($search) !== '')
                    <p>Tidak ada kios cocok dengan "{{ $search }}".</p>
                    <p class="text-sm mt-1">Coba kata kunci lain atau kosongkan pencarian.</p>
                @else
                    <p>Belum ada kios di area ini.</p>
                    <p class="text-sm mt-1">Tambah kios dulu via menu Kios Baru.</p>
                @endif
            </div>
        @endforelse
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
                        <input type="number" min="1" wire:model.live="walkInMika"
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
                        <input type="number" min="0" wire:model="dropBaru"
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
                                <input type="number" min="0" wire:model="returnFresh"
                                       class="w-full rounded-lg border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 text-center text-lg font-bold">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-700 mb-1">Dodol Sisa/Basi (Biji)</label>
                                <input type="number" min="0" wire:model="returnExpired"
                                       class="w-full rounded-lg border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 text-center text-lg font-bold text-red-600">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Uang Diterima (Rp)</label>
                            <input type="number" min="0" wire:model="uangDiterima"
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
                        <span>— Kios Lama (Pergantian)</span>
                        <span>{{ $tripSummary['kios_lama'] }}</span>
                    </div>
                    <div class="flex justify-between pl-3 text-xs text-slate-500">
                        <span>— Kios Baru (Tempat Baru)</span>
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
                {{-- Foto kios di puncak modal: bantu operator pastikan kios yang benar --}}
                @if($selectedKiosk->photo_url)
                    <div class="mb-3 -mx-4 -mt-2">
                        <img src="{{ $selectedKiosk->photo_url }}"
                             alt="Foto {{ $selectedKiosk->name }}"
                             class="w-full max-h-36 object-cover">
                    </div>
                @endif

                {{-- Error umum --}}
                @error('general')
                    <div class="bg-red-50 border border-red-200 rounded-lg p-3 text-sm text-red-700">{{ $message }}</div>
                @enderror

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
                                <input type="number" wire:model="piutangBayar" min="1" max="{{ $piutangLama }}"
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
                                        <input type="number" wire:model.live="returnFresh" class="w-full rounded-lg border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 text-center text-lg font-bold" min="0">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-slate-700 mb-1">Dodol Sisa/Basi (Biji)<br><span class="text-[10px] font-normal text-slate-400">(dodol rusak/basi yang diretur)</span></label>
                                        <input type="number" wire:model.live="returnExpired" class="w-full rounded-lg border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 text-center text-lg font-bold text-red-600" min="0">
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
                                    <input type="number" wire:model="uangDiterima" class="w-full rounded-lg border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 text-xl font-bold text-green-700 bg-white" min="0">
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

                        <p class="text-xs font-bold text-slate-500 uppercase tracking-wider">Mau ngapain di kios ini?</p>
                        <div class="space-y-3">
                            <button type="button" wire:click="chooseAction('tagih_titip')"
                                class="w-full text-left p-4 rounded-xl bg-amber-600 text-white shadow-sm active:bg-amber-700">
                                <span class="font-bold text-base block">💰 Tagih + Titip Baru</span>
                                <span class="text-xs text-amber-100 mt-0.5 block">Ambil bayaran titipan lama, lalu titip dodol baru (paling umum)</span>
                            </button>
                            {{-- "Tagih Saja" & "Tunda Bayar" DICABUT (Tahap 1 & 2):
                                 - Bayaran tanpa titip → "Hentikan Kedai".
                                 - Tunda bayar → "Cek Sisa" pilih alasan "belum bisa bayar". --}}
                            <button type="button" wire:click="chooseAction('cek')"
                                class="w-full text-left p-4 rounded-xl bg-white border-2 border-slate-200 active:bg-slate-50">
                                <span class="font-bold text-base text-slate-900 block">👀 Cek Sisa (tanpa tagih)</span>
                                <span class="text-xs text-slate-500 mt-0.5 block">Catat sisa &amp; kondisi kios (termasuk pemilik belum bisa bayar). Titipan TETAP berjalan — tidak ditagih, tidak dilunasi.</span>
                            </button>
                        </div>

                        {{-- Hentikan Kedai (stop titipan) — satu pintu, pilih cara di layar berikutnya --}}
                        <button type="button" wire:click="startStop"
                            class="w-full mt-4 text-center p-3 rounded-xl border border-red-200 bg-red-50 text-sm font-bold text-red-700 active:bg-red-100">
                            ⛔ Hentikan Kedai Ini (stop titipan)
                        </button>
                    @else
                        <p class="text-xs font-bold text-slate-500 uppercase tracking-wider">Mau ngapain di kios ini?</p>
                        <div class="space-y-3">
                            <button type="button" wire:click="chooseAction('titip')"
                                class="w-full text-left p-4 rounded-xl bg-amber-600 text-white shadow-sm active:bg-amber-700">
                                <span class="font-bold text-base block">📦 Titip Baru</span>
                                <span class="text-xs text-amber-100 mt-0.5 block">Titip dodol ke kios ini (tidak ada tunggakan)</span>
                            </button>
                            <button type="button" wire:click="chooseAction('cek')"
                                class="w-full text-left p-4 rounded-xl bg-white border-2 border-slate-200 active:bg-slate-50">
                                <span class="font-bold text-base text-slate-900 block">👀 Cek Saja</span>
                                <span class="text-xs text-slate-500 mt-0.5 block">Catat kunjungan tanpa transaksi (kios tutup, dodol masih ada, dll)</span>
                            </button>
                        </div>

                        {{-- Hentikan Kedai (stop titipan) — satu pintu, pilih cara di layar berikutnya --}}
                        <button type="button" wire:click="startStop"
                            class="w-full mt-4 text-center p-3 rounded-xl border border-red-200 bg-red-50 text-sm font-bold text-red-700 active:bg-red-100">
                            ⛔ Hentikan Kedai Ini (stop titipan)
                        </button>
                    @endif
                @else
                    {{-- ============ LAYAR 2: FORM SESUAI AKSI ============ --}}

                    @unless($isCashOnly)
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-bold text-amber-700">
                                @switch($chosenAction)
                                    @case('tagih_titip') 💰 Tagih + Titip Baru @break
                                    @case('titip') 📦 Titip Baru @break
                                    @case('cek') 👀 Cek Saja @break
                                @endswitch
                            </span>
                            <button type="button" wire:click="backToActionPicker"
                                class="text-xs font-semibold text-slate-500 bg-slate-100 px-3 py-2 min-h-[36px] rounded-lg active:bg-slate-200">
                                ← Ganti Aksi
                            </button>
                        </div>
                    @endunless

                    {{-- AREA TAGIHAN (tagih / tagih+titip) --}}
                    @if($chosenAction === 'tagih_titip' && $pendingDelivery)
                        <div class="bg-amber-50 border border-amber-200 rounded-xl p-4">
                            <div class="flex justify-between items-center mb-4">
                                <span class="text-xs font-bold text-amber-800 uppercase tracking-wider">Titipan Sebelumnya</span>
                                <span class="text-sm font-bold text-slate-900">{{ $pendingDelivery->qty_delivered }} Mika ({{ $pendingDelivery->qty_delivered * 15 }} Biji)</span>
                            </div>

                            <div class="grid grid-cols-2 gap-4 mb-4">
                                <div>
                                    <label class="block text-xs font-medium text-slate-700 mb-1">Sisa Bagus (Biji)</label>
                                    <input type="number" wire:model.live="returnFresh" class="w-full rounded-lg border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 text-center text-lg font-bold" min="0">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-700 mb-1">Dodol Sisa/Basi (Biji)<br><span class="text-[10px] font-normal text-slate-400">(dodol rusak/basi yang diretur)</span></label>
                                    <input type="number" wire:model.live="returnExpired" class="w-full rounded-lg border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 text-center text-lg font-bold text-red-600" min="0">
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
                                <input type="number" wire:model.live.debounce.500ms="uangDiterima" class="w-full rounded-lg border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 text-xl font-bold text-green-700 bg-white" min="0">
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

                    {{-- AREA TITIP BARU (titip / tagih+titip / cash) --}}
                    @if(in_array($chosenAction, ['tagih_titip', 'titip', 'cash'], true))
                        <div class="bg-white border border-slate-200 rounded-xl p-4">
                            <label class="block text-sm font-bold text-slate-900 mb-2">
                                {{ $isCashOnly ? 'Jumlah Jual Cash (Mika)' : 'Titip Baru (Mika)' }}
                            </label>
                            <div class="flex items-center gap-3">
                                <button type="button" wire:click="decrementDrop" class="w-12 h-12 rounded-lg bg-slate-100 border border-slate-200 text-slate-600 text-xl font-bold flex items-center justify-center active:bg-slate-200">-</button>

                                <input type="number" id="dropBaru" wire:model.live.debounce.500ms="dropBaru" class="flex-1 rounded-lg border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 text-center text-2xl font-bold" min="0">

                                <button type="button" wire:click="incrementDrop" class="w-12 h-12 rounded-lg bg-slate-100 border border-slate-200 text-slate-600 text-xl font-bold flex items-center justify-center active:bg-slate-200">+</button>
                            </div>

                            @if(!empty($selectedKiosk->default_qty_mika) && !$isCashOnly)
                                <p class="mt-2 text-xs text-slate-400">Biasanya kios ini dititip {{ (int) $selectedKiosk->default_qty_mika }} mika</p>
                            @endif

                            @if($isCashOnly)
                                <p class="mt-2 text-xs font-medium text-emerald-700">Penjualan cash — langsung lunas, tanpa titipan.</p>
                                @if((int) $dropBaru > 0)
                                    <p class="mt-1 text-sm font-bold text-emerald-700">Total: Rp {{ number_format((int) $dropBaru * 15 * 800, 0, ',', '.') }}</p>
                                @endif
                            @elseif(!empty($selectedKiosk->default_qty_mika) && (int) $dropBaru > (int) $selectedKiosk->default_qty_mika)
                                <div class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-4">
                                    <p class="text-sm font-bold text-amber-800 mb-3">
                                        Titip melebihi kebiasaan ({{ (int) $selectedKiosk->default_qty_mika }} mika).
                                        Kelebihan {{ (int) $dropBaru - (int) $selectedKiosk->default_qty_mika }} mika:
                                    </p>
                                    <div class="space-y-2">
                                        <label class="flex items-center gap-3 cursor-pointer p-2 rounded-lg {{ $extraDropMode === 'cash' ? 'bg-white border border-amber-300' : '' }}">
                                            <input type="radio" wire:model.live="extraDropMode" value="cash" class="text-amber-600">
                                            <span class="text-sm text-slate-700">💵 Bayar cash sekarang</span>
                                        </label>
                                        <label class="flex items-center gap-3 cursor-pointer p-2 rounded-lg {{ $extraDropMode === 'konsinyasi' ? 'bg-white border border-amber-300' : '' }}">
                                            <input type="radio" wire:model.live="extraDropMode" value="konsinyasi" class="text-amber-600">
                                            <span class="text-sm text-slate-700">
                                                📦 Semua jadi titipan + jadikan {{ (int) $dropBaru }} mika kebiasaan baru
                                            </span>
                                        </label>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif

                    {{-- CEK SAJA: alasan + sisa biji --}}
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
                                <label class="block text-sm font-bold text-slate-700 mb-2">Sisa Dodol di Kios (Biji)</label>
                                <input type="number" wire:model="sisaBiji" class="w-full rounded-xl border-slate-300 text-center text-xl font-bold py-3" min="0" placeholder="0">
                                <p class="text-xs text-slate-400 mt-1">Isi 0 kalau tidak tahu atau kios tutup</p>
                            </div>
                        </div>
                    @endif

                    {{-- OPSI KHUSUS (collapsed): Dodol Sisa redistribusi + turunkan kebiasaan --}}
                    @if(in_array($chosenAction, ['tagih_titip', 'titip'], true))
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
                                @if(in_array($chosenAction, ['tagih_titip', 'titip'], true))
                                    <div>
                                        <label class="flex items-center gap-3 cursor-pointer p-3 rounded-xl border border-slate-200">
                                            <input type="checkbox" wire:model.live="adaBsRedistribusi" class="rounded text-amber-600">
                                            <span class="text-sm font-medium text-slate-700">Ada mika Dodol Sisa dari kios lain ikut dititip</span>
                                        </label>
                                        @if($adaBsRedistribusi)
                                            <div class="mt-2">
                                                <label class="text-xs text-slate-500">Jumlah mika Dodol Sisa yang ikut (mika)</label>
                                                <input type="number" wire:model.live="qtyBsMika" min="1" class="w-full rounded-xl border-slate-300 text-center font-bold py-2 mt-1">
                                                <p class="text-xs text-slate-400 mt-1">
                                                    Total titip ke kios ini: {{ (int) $dropBaru + (int) $qtyBsMika }} mika
                                                    ({{ (int) $dropBaru }} baru + {{ (int) $qtyBsMika }} Dodol Sisa)
                                                </p>
                                            </div>
                                        @endif
                                    </div>
                                @endif

                                {{-- SKENARIO 4: turunkan default qty — hanya saat tagih --}}
                                @if($chosenAction === 'tagih_titip' && (int) $selectedKiosk->default_qty_mika > 1)
                                    <div>
                                        <label class="flex items-center gap-3 cursor-pointer p-3 rounded-xl border border-slate-200">
                                            <input type="checkbox" wire:model.live="turunkanDefault" class="rounded text-amber-600">
                                            <span class="text-sm font-medium text-slate-700">Kurangi jatah titipan kios ini<br><span class="text-xs font-normal text-slate-400">(jatah = jumlah mika yang biasa dititip ke kios ini ke depannya, bukan tagihan sekarang)</span></span>
                                        </label>
                                        @if($turunkanDefault)
                                            <div class="mt-2">
                                                <label class="text-xs text-slate-500">Jatah baru (mika)</label>
                                                <input type="number" wire:model="qtyDefaultBaru" min="1" max="{{ (int) $selectedKiosk->default_qty_mika - 1 }}" class="w-full rounded-xl border-slate-300 text-center font-bold py-2 mt-1">
                                                <p class="text-xs text-amber-600 mt-1">Titipan berikutnya: {{ $qtyDefaultBaru ?: '?' }} mika</p>
                                                <p class="text-xs text-slate-500 mt-1">ℹ️ Berlaku untuk titipan BERIKUTNYA, bukan titip sekarang</p>
                                                @if($dropBaru > 0 && $qtyDefaultBaru > 0 && (int) $dropBaru > (int) $qtyDefaultBaru)
                                                    <p class="text-xs text-red-600 font-semibold mt-1.5">⚠️ Perhatian: Titip sekarang ({{ $dropBaru }} mika) lebih besar dari jatah baru ({{ $qtyDefaultBaru }} mika).</p>
                                                @endif
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
                // Aksi yang WAJIB ada titipan baru (drop > 0) sebelum bisa disimpan.
                // tagih_titip ikut: "ambil bayaran TANPA titip" = pintu belakang yang
                // ditutup → arahkan ke "Hentikan Kedai". Tunda/Cek (drop=0) TIDAK kena.
                $needDrop = in_array($chosenAction, ['titip', 'cash', 'tagih_titip'], true) && (int) $dropBaru < 1;
                $saveLabel = ($chosenAction === 'tagih_titip' && (int) $dropBaru < 1)
                    ? 'Isi jumlah titipan dulu'
                    : ($needDrop ? 'Isi jumlah mika dulu' : 'Simpan Kunjungan');
            @endphp
            <div class="sticky bottom-0 bg-white border-t border-slate-100 p-4 z-10">
                @if($chosenAction === 'tagih_titip' && (int) $dropBaru < 1)
                    <p class="text-xs text-slate-500 mb-2 text-center">
                        Kalau cuma ambil bayaran tanpa titip baru, pakai <span class="font-semibold text-red-700">⛔ Hentikan Kedai</span>.
                    </p>
                @endif
                <button type="button" wire:click="saveVisit" wire:loading.attr="disabled" wire:target="saveVisit"
                    @disabled($needDrop)
                    class="w-full bg-slate-900 text-white font-bold text-lg py-3 rounded-xl shadow-sm active:bg-slate-800 disabled:opacity-60">
                    <span wire:loading.remove wire:target="saveVisit">{{ $saveLabel }}</span>
                    <span wire:loading wire:target="saveVisit">Menyimpan...</span>
                </button>
            </div>
            @endif
            
        </div>
    </div>
    @endif
</div>