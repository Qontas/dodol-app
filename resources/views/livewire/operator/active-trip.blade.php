<div class="max-w-md mx-auto pb-20" x-data="{
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
                    class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold shadow-sm ring-1 ring-inset transition-colors
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
                <span x-text="loading ? 'Mencari...' : '{{ $sortedByDistance ? 'Terurut by Jarak' : 'Urutkan by Jarak' }}'">Urutkan by Jarak</span>
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
                    </div>
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
    <div class="fixed bottom-0 left-0 right-0 p-4 bg-white border-t border-slate-200 max-w-md mx-auto">
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
                    <div class="border-t border-slate-200 my-2"></div>
                    <div class="flex justify-between text-green-700 font-semibold">
                        <span>Omset (Cash Diterima)</span>
                        <span>Rp {{ number_format($tripSummary['total_uang_diterima'], 0, ',', '.') }}</span>
                    </div>
                    <div class="flex justify-between text-slate-600">
                        <span>HPP (Terjual × 9.500)</span>
                        <span>Rp {{ number_format($tripSummary['hpp_estimasi'], 0, ',', '.') }}</span>
                    </div>
                    <div class="flex justify-between text-slate-800 font-medium">
                        <span>Untung Kotor (Terjual × 2.500)</span>
                        <span>Rp {{ number_format($tripSummary['untung_kotor'], 0, ',', '.') }}</span>
                    </div>
                    <div class="border-t border-slate-200 my-2"></div>
                    <div class="flex justify-between text-slate-500 text-xs">
                        <span>Komisi Reguler (Terjual × 500)</span>
                        <span>Rp {{ number_format($tripSummary['komisi_reguler'], 0, ',', '.') }}</span>
                    </div>
                    <div class="flex justify-between text-slate-500 text-xs">
                        <span>Komisi Kios Baru (Kios Baru × 1.000)</span>
                        <span>Rp {{ number_format($tripSummary['komisi_kios_baru'], 0, ',', '.') }}</span>
                    </div>
                    <div class="flex justify-between text-amber-700 font-bold">
                        <span>Total Komisi Rian</span>
                        <span>Rp {{ number_format($tripSummary['komisi_rian'], 0, ',', '.') }}</span>
                    </div>
                    <div class="border-t border-slate-200 my-2"></div>
                    <div class="flex justify-between text-slate-900 font-bold text-base">
                        <span>Untung Bersih Owner</span>
                        <span>Rp {{ number_format($tripSummary['untung_bersih_owner'], 0, ',', '.') }}</span>
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
                    <h3 class="font-bold text-lg text-slate-900">{{ $selectedKiosk->name }}</h3>
                    <p class="text-xs text-slate-500">Form Serah Terima</p>
                </div>
                <button wire:click="closeVisitModal" class="p-2 bg-slate-100 text-slate-500 rounded-full hover:bg-slate-200">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>

            <div class="p-5 space-y-6">
                {{-- Foto + Navigasi Kios --}}
                @if($selectedKiosk->photo_path || ($selectedKiosk->latitude && $selectedKiosk->longitude))
                    <div class="space-y-3">
                        @if($selectedKiosk->photo_path)
                            <img src="{{ Storage::url($selectedKiosk->photo_path) }}"
                                 alt="Foto {{ $selectedKiosk->name }}"
                                 class="w-full rounded-xl object-cover" style="max-height: 160px;">
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

                {{-- Error umum --}}
                @error('general')
                    <div class="bg-red-50 border border-red-200 rounded-lg p-3 text-sm text-red-700">{{ $message }}</div>
                @enderror

                {{-- Aksi yang akan dilakukan (auto-detect) --}}
                <div class="flex items-center justify-between text-xs">
                    <span class="text-slate-500">Aksi:</span>
                    <span class="font-bold text-amber-700">
                        @switch($this->visitAction)
                            @case('drop_and_settle') Settle + Drop Baru @break
                            @case('drop_only') Drop Baru @break
                            @case('settle_only') Settle Saja @break
                            @default Kunjungan (Cek)
                        @endswitch
                    </span>
                </div>

                @if($pendingDelivery)
                    {{-- Peringatan jumlah perpanjangan --}}
                    @if($extensionCount >= 2)
                        <div class="bg-red-50 border border-red-200 rounded-lg p-3 text-sm text-red-700 font-medium">
                            ⚠️ Sudah 2x perpanjangan — Pertimbangkan Cut Off
                        </div>
                    @elseif($extensionCount === 1)
                        <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-sm text-amber-700">
                            Perpanjangan ke-1 dari 2
                        </div>
                    @endif

                    {{-- AREA SETTLEMENT (Jika ada titipan lama) --}}
                    <div class="bg-amber-50 border border-amber-200 rounded-xl p-4">
                        <div class="flex justify-between items-center mb-4">
                            <span class="text-xs font-bold text-amber-800 uppercase tracking-wider">Titipan Sebelumnya</span>
                            <span class="text-sm font-bold text-slate-900">{{ $pendingDelivery->qty_delivered }} Mika ({{ $pendingDelivery->qty_delivered * 15 }} Biji)</span>
                        </div>

                        @if(!$extensionGranted)
                            <div class="grid grid-cols-2 gap-4 mb-4">
                                <div>
                                    <label class="block text-xs font-medium text-slate-700 mb-1">Sisa Bagus (Biji)</label>
                                    <input type="number" wire:model.live="returnFresh" class="w-full rounded-lg border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 text-center text-lg font-bold" min="0">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-700 mb-1">Sisa Basi/BS (Biji)</label>
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
                        @else
                            <div class="text-sm text-amber-800 bg-white/60 rounded-lg p-3">
                                Settle ditunda. BS &amp; pembayaran diambil di kunjungan berikutnya — titipan ini tetap tercatat sebagai tunggakan.
                            </div>
                        @endif
                    </div>

                    {{-- Toggle perpanjangan (tunda settle) --}}
                    <div class="flex items-center gap-3 p-3 bg-slate-50 rounded-lg border border-slate-200">
                        <input type="checkbox" wire:model.live="extensionGranted" id="extensionToggle"
                            class="rounded border-slate-300 text-amber-600 focus:ring-amber-500">
                        <label for="extensionToggle" class="text-sm font-medium text-slate-700 cursor-pointer">
                            Tunda bayar &amp; ambil BS (perpanjangan)
                        </label>
                    </div>
                @else
                    {{-- TAMPILAN KIOS BARU --}}
                    <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 text-center">
                        <span class="text-2xl mb-2 block">✨</span>
                        <h4 class="text-sm font-bold text-blue-900">Kios Baru / Tidak Ada Tunggakan</h4>
                        <p class="text-xs text-blue-700 mt-1">Lanjut ke pengisian titipan baru di bawah.</p>
                    </div>
                @endif

                {{-- AREA DROP BARU (Selalu Muncul) --}}
                <div class="bg-white border border-slate-200 rounded-xl p-4">
                    <label class="block text-sm font-bold text-slate-900 mb-2">Drop Titipan Baru (Mika)</label>
                    <div class="flex items-center gap-3">
                        <button type="button" wire:click="decrementDrop" class="w-12 h-12 rounded-lg bg-slate-100 border border-slate-200 text-slate-600 text-xl font-bold flex items-center justify-center active:bg-slate-200">-</button>
                        
                        <input type="number" id="dropBaru" wire:model.live="dropBaru" class="flex-1 rounded-lg border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 text-center text-2xl font-bold" min="0">
                        
                        <button type="button" wire:click="incrementDrop" class="w-12 h-12 rounded-lg bg-slate-100 border border-slate-200 text-slate-600 text-xl font-bold flex items-center justify-center active:bg-slate-200">+</button>
                    </div>
                </div>
            </div>

            <div class="sticky bottom-0 bg-white border-t border-slate-100 p-4 z-10">
                <button type="button" wire:click="saveVisit" wire:loading.attr="disabled" wire:target="saveVisit" class="w-full bg-slate-900 text-white font-bold text-lg py-3 rounded-xl shadow-sm active:bg-slate-800 disabled:opacity-60">
                    <span wire:loading.remove wire:target="saveVisit">Simpan Kunjungan</span>
                    <span wire:loading wire:target="saveVisit">Menyimpan...</span>
                </button>
            </div>
            
        </div>
    </div>
    @endif
</div>