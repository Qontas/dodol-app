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
                    <p class="text-slate-600 text-sm mt-1">Area: <span class="text-slate-500 font-semibold">Semua Kios (Uncategorized)</span></p>
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
        <div class="flex justify-between items-center mb-2">
            <h2 class="text-sm font-bold text-slate-900 uppercase tracking-wider">Daftar Kunjungan</h2>
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
                        @if($hasPending)
                            <span class="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full">Ada Titipan</span>
                        @endif

                        {{-- Smart Kios Flags --}}
                        @php $flags = $kioskFlags[$kiosk->id] ?? []; @endphp
                        @if(in_array('urgent', $flags))
                            <span class="text-xs bg-red-100 text-red-700 px-2 py-0.5 rounded-full font-bold">🔴 URGENT</span>
                        @elseif(in_array('warning', $flags))
                            <span class="text-xs bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full">⚠️ Hampir Expired</span>
                        @endif
                        @if(in_array('fast_mover', $flags))
                            <span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full">⚡ Fast Mover</span>
                        @endif
                        @if(in_array('slow_mover', $flags))
                            <span class="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full">🐢 Slow Mover</span>
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
                @endif
            </div>
        @empty
            <div class="text-center py-8 text-slate-400">
                <p>Belum ada kios di area ini.</p>
                <p class="text-sm mt-1">Tambah kios dulu via menu Kios Baru.</p>
            </div>
        @endforelse
    </div>

    {{-- Tombol Akhiri Trip Fix di Bawah --}}
    <div class="fixed bottom-[48px] left-0 right-0 p-4 bg-white border-t border-slate-200 max-w-md mx-auto z-30 shadow-md">
        <button type="button" wire:click="openEndTripModal" wire:loading.attr="disabled" wire:target="openEndTripModal" class="w-full bg-red-600 text-white font-bold text-lg py-3 rounded-xl shadow-sm active:bg-red-700">
            <span wire:loading.remove wire:target="openEndTripModal">Akhiri Trip</span>
            <span wire:loading wire:target="openEndTripModal">Memuat...</span>
        </button>
    </div>

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
                        <span class="text-slate-600">Total Drop</span>
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
                {{-- Error umum --}}
                @error('general')
                    <div class="bg-red-50 border border-red-200 rounded-lg p-3 text-sm text-red-700">{{ $message }}</div>
                @enderror

                @if(is_null($chosenAction))
                    {{-- ============ LAYAR 1: PILIH AKSI ============ --}}

                    {{-- Foto + Navigasi hanya di layar pertama (bantu konfirmasi kios yang benar) --}}
                    @if($selectedKiosk->photo_path || ($selectedKiosk->latitude && $selectedKiosk->longitude))
                        <div class="space-y-3">
                            @if($selectedKiosk->photo_path)
                                <img src="{{ Storage::url($selectedKiosk->photo_path) }}"
                                     alt="Foto {{ $selectedKiosk->name }}"
                                     class="w-full rounded-xl object-cover" style="max-height: 140px;">
                            @endif

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
                        </div>
                    @endif

                    @if($pendingDelivery)
                        <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 flex justify-between items-center text-sm">
                            <span class="font-medium text-amber-800">Titipan sebelumnya</span>
                            <span class="font-bold text-slate-900">{{ $pendingDelivery->qty_delivered }} mika ({{ $pendingDelivery->qty_delivered * 15 }} biji)</span>
                        </div>

                        @if($extensionCount >= 2)
                            <div class="bg-red-50 border border-red-200 rounded-lg p-3 text-sm text-red-700 font-medium">
                                ⚠️ Sudah 2x tunda bayar — Pertimbangkan Cut Off
                            </div>
                        @endif

                        <p class="text-xs font-bold text-slate-500 uppercase tracking-wider">Mau ngapain di kios ini?</p>
                        <div class="space-y-3">
                            <button type="button" wire:click="chooseAction('tagih_titip')"
                                class="w-full text-left p-4 rounded-xl bg-amber-600 text-white shadow-sm active:bg-amber-700">
                                <span class="font-bold text-base block">💰 Tagih + Titip Baru</span>
                                <span class="text-xs text-amber-100 mt-0.5 block">Ambil bayaran titipan lama, lalu titip dodol baru (paling umum)</span>
                            </button>
                            <button type="button" wire:click="chooseAction('tagih')"
                                class="w-full text-left p-4 rounded-xl bg-white border-2 border-slate-200 active:bg-slate-50">
                                <span class="font-bold text-base text-slate-900 block">🧾 Tagih Saja</span>
                                <span class="text-xs text-slate-500 mt-0.5 block">Ambil bayaran saja, tidak titip baru</span>
                            </button>
                            <button type="button" wire:click="chooseAction('tunda')"
                                class="w-full text-left p-4 rounded-xl bg-white border-2 border-slate-200 active:bg-slate-50">
                                <span class="font-bold text-base text-slate-900 block">⏳ Tunda Bayar
                                    @if($extensionCount > 0)
                                        <span class="ml-1 text-[10px] font-bold text-red-600 bg-red-50 px-1.5 py-0.5 rounded-full align-middle">sudah {{ $extensionCount }}x</span>
                                    @endif
                                </span>
                                <span class="text-xs text-slate-500 mt-0.5 block">Kios belum bisa bayar — tagih di kunjungan berikutnya (max 2x)</span>
                            </button>
                        </div>
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
                    @endif
                @else
                    {{-- ============ LAYAR 2: FORM SESUAI AKSI ============ --}}

                    @unless($isCashOnly)
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-bold text-amber-700">
                                @switch($chosenAction)
                                    @case('tagih_titip') 💰 Tagih + Titip Baru @break
                                    @case('tagih') 🧾 Tagih Saja @break
                                    @case('tunda') ⏳ Tunda Bayar @break
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

                    {{-- TUNDA BAYAR --}}
                    @if($chosenAction === 'tunda')
                        @if($extensionCount >= 2)
                            <div class="bg-red-50 border border-red-200 rounded-lg p-3 text-sm text-red-700 font-medium">
                                ⚠️ Sudah 2x tunda bayar — Pertimbangkan Cut Off
                            </div>
                        @elseif($extensionCount === 1)
                            <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-sm text-amber-700">
                                Tunda bayar ke-1 dari 2
                            </div>
                        @endif

                        <div class="bg-amber-50 border border-amber-200 rounded-xl p-4">
                            <div class="flex justify-between items-center mb-3">
                                <span class="text-xs font-bold text-amber-800 uppercase tracking-wider">Titipan Sebelumnya</span>
                                <span class="text-sm font-bold text-slate-900">{{ $pendingDelivery->qty_delivered }} Mika ({{ $pendingDelivery->qty_delivered * 15 }} Biji)</span>
                            </div>
                            <div class="text-sm text-amber-800 bg-white/60 rounded-lg p-3">
                                Pembayaran ditunda. Dodol sisa &amp; pembayaran diambil di kunjungan berikutnya — titipan ini tetap tercatat sebagai tunggakan.
                            </div>
                        </div>
                    @endif

                    {{-- AREA TAGIHAN (tagih / tagih+titip) --}}
                    @if(in_array($chosenAction, ['tagih_titip', 'tagih'], true) && $pendingDelivery)
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
                                    <label class="block text-xs font-medium text-slate-700 mb-1">Dodol Sisa/Basi (Biji)</label>
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
                                <div class="grid grid-cols-2 gap-2">
                                    @foreach([
                                        'kios_tutup' => '🔒 Kios Tutup',
                                        'pemilik_minta_tunggu' => '⏳ Minta Tunggu',
                                        'tidak_ada_uang' => '💸 Tidak Ada Uang',
                                        'dodol_masih_banyak' => '📦 Dodol Masih Ada',
                                    ] as $val => $label)
                                        <label class="flex items-center gap-2 p-3 rounded-xl border cursor-pointer {{ $alasanCheck === $val ? 'border-amber-400 bg-amber-50' : 'border-slate-200' }}">
                                            <input type="radio" wire:model.live="alasanCheck" value="{{ $val }}" class="sr-only">
                                            <span class="text-sm font-medium">{{ $label }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm font-bold text-slate-700 mb-2">Sisa Dodol di Kios (Biji)</label>
                                <input type="number" wire:model="sisaBiji" class="w-full rounded-xl border-slate-300 text-center text-xl font-bold py-3" min="0" placeholder="0">
                                <p class="text-xs text-slate-400 mt-1">Isi 0 kalau tidak tahu atau kios tutup</p>
                            </div>
                        </div>
                    @endif

                    {{-- OPSI KHUSUS (collapsed): Dodol Sisa redistribusi + turunkan kebiasaan --}}
                    @if(in_array($chosenAction, ['tagih_titip', 'titip', 'tagih'], true))
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
                                @if(in_array($chosenAction, ['tagih_titip', 'tagih'], true) && (int) $selectedKiosk->default_qty_mika > 1)
                                    <div>
                                        <label class="flex items-center gap-3 cursor-pointer p-3 rounded-xl border border-slate-200">
                                            <input type="checkbox" wire:model.live="turunkanDefault" class="rounded text-amber-600">
                                            <span class="text-sm font-medium text-slate-700">Kurangi jatah titipan kios ini</span>
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
            <div class="sticky bottom-0 bg-white border-t border-slate-100 p-4 z-10">
                <button type="button" wire:click="saveVisit" wire:loading.attr="disabled" wire:target="saveVisit"
                    @disabled(in_array($chosenAction, ['titip', 'cash'], true) && (int) $dropBaru < 1)
                    class="w-full bg-slate-900 text-white font-bold text-lg py-3 rounded-xl shadow-sm active:bg-slate-800 disabled:opacity-60">
                    <span wire:loading.remove wire:target="saveVisit">
                        {{ in_array($chosenAction, ['titip', 'cash'], true) && (int) $dropBaru < 1 ? 'Isi jumlah mika dulu' : 'Simpan Kunjungan' }}
                    </span>
                    <span wire:loading wire:target="saveVisit">Menyimpan...</span>
                </button>
            </div>
            @endif
            
        </div>
    </div>
    @endif
</div>