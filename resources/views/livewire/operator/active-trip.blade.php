<div class="max-w-md mx-auto pb-20">
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
        <h2 class="text-sm font-bold text-slate-900 uppercase tracking-wider mb-2">Daftar Kunjungan</h2>
        
        @forelse ($kiosks as $kiosk)
            <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm hover:border-amber-400 transition-colors">
                <div class="flex justify-between items-start mb-3">
                    <div>
                        <h3 class="font-bold text-slate-900">{{ $kiosk->name }}</h3>
                        <p class="text-xs text-slate-500 mt-0.5">Siklus: {{ $kiosk->target_visit_interval_days }} hari</p>
                    </div>
                    <span class="w-3 h-3 rounded-full bg-slate-300"></span>
                </div>
                
                <div class="grid grid-cols-2 gap-2 mt-4">
                    <button type="button" wire:click="openVisitModal({{ $kiosk->id }})" wire:loading.attr="disabled" wire:target="openVisitModal" class="w-full bg-amber-100 text-amber-900 text-sm font-semibold py-2 rounded-lg border border-amber-200 active:bg-amber-200 transition-all">
                        <span wire:loading.remove wire:target="openVisitModal">Settle & Drop</span>
                        <span wire:loading wire:target="openVisitModal">Memuat...</span>
                    </button>
                    <button type="button" class="w-full bg-slate-100 text-slate-700 text-sm font-semibold py-2 rounded-lg border border-slate-200 active:bg-slate-200">
                        Lainnya...
                    </button>
                </div>
            </div>
        @empty
            <div class="text-center py-10 bg-slate-50 rounded-xl border border-slate-200">
                <p class="text-slate-500 text-sm">Tidak ada kios aktif di area ini.</p>
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
                <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 space-y-2">
                    <div class="flex justify-between text-sm">
                        <span class="text-slate-600">Kios Dikunjungi</span>
                        <span class="font-bold text-slate-900">{{ $tripSummary['kios_visited'] }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-slate-600">Total Drop</span>
                        <span class="font-bold text-slate-900">{{ $tripSummary['total_mika_drop'] }} mika</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-slate-600">Total Uang Diterima</span>
                        <span class="font-bold text-green-700">Rp {{ number_format($tripSummary['total_uang_diterima'], 0, ',', '.') }}</span>
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