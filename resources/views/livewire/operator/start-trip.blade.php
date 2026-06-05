<div class="max-w-md mx-auto">
    {{-- Header --}}
    <div class="mb-6">
        <a href="{{ route('operator.dashboard') }}" class="text-slate-500 text-sm flex items-center gap-1 hover:text-slate-800">
            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M9.707 14.707a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 1.414L7.414 9H15a1 1 0 110 2H7.414l2.293 2.293a1 1 0 010 1.414z" clip-rule="evenodd"/>
            </svg>
            Kembali ke Dashboard
        </a>
        <h1 class="text-2xl font-bold text-slate-900 mt-2">Mulai Trip</h1>
        <p class="text-slate-500 text-sm">Pilih cluster awal yang akan dikunjungi</p>
    </div>

    {{-- Cluster List --}}
    <div class="space-y-3">
        @forelse ($clusters as $cluster)
            <button
                type="button"
                wire:click="$set('selectedClusterId', {{ $cluster->id }})"
                class="w-full text-left p-4 rounded-xl border-2 transition-all
                    {{ $selectedClusterId === (int) $cluster->id
                        ? 'border-amber-500 bg-amber-50'
                        : 'border-slate-200 bg-white hover:border-slate-300' }}">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2">
                            @php
                                $dotColor = match ($cluster->urgency_data['level']) {
                                    'high' => 'bg-red-500',
                                    'medium' => 'bg-yellow-500',
                                    'low' => 'bg-green-500',
                                    'empty' => 'bg-slate-300',
                                    default => 'bg-cyan-500',
                                };
                            @endphp
                            <span class="inline-block w-3 h-3 rounded-full {{ $dotColor }} flex-shrink-0"></span>
                            <h3 class="font-bold text-lg text-slate-900 truncate">{{ $cluster->name }}</h3>
                        </div>

                        <p class="text-sm text-slate-600 mt-1">
                            {{ $cluster->kiosks_count }} kios aktif
                        </p>

                        <p class="text-xs text-slate-500 mt-2">
                            {{ $cluster->urgency_data['message'] }}
                        </p>
                    </div>

                    @if ($selectedClusterId === (int) $cluster->id)
                        <svg class="w-6 h-6 text-amber-600 flex-shrink-0 mt-1" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                        </svg>
                    @endif
                </div>
            </button>
        @empty
            <div class="text-center p-8 bg-white border border-slate-200 rounded-xl">
                <svg class="mx-auto h-12 w-12 text-slate-300 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/>
                </svg>
                <p class="text-slate-700 font-medium">Belum ada cluster aktif</p>
                <p class="text-xs text-slate-500 mt-2">Hubungi owner untuk setup cluster dulu</p>
            </div>
        @endforelse
    </div>

    {{-- Error Message --}}
    @error('selectedClusterId')
        <p class="mt-3 text-sm text-red-600 text-center">{{ $message }}</p>
    @enderror

    {{-- Input Jumlah Mika Dibawa --}}
    @if (count($clusters) > 0)
        <div class="mt-6">
            <label for="qtyCarried" class="block text-sm font-bold text-slate-900 mb-2">
                Berapa mika yang kamu bawa hari ini?
            </label>
            <input
                type="number"
                id="qtyCarried"
                wire:model.live="qtyCarried"
                min="0"
                inputmode="numeric"
                class="w-full rounded-xl border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 text-center text-2xl font-bold py-3">
            @error('qtyCarried')
                <p class="mt-2 text-sm text-red-600 text-center">{{ $message }}</p>
            @enderror
        </div>
    @endif

    {{-- CTA Button --}}
    @if (count($clusters) > 0)
        <div class="mt-6">
            <button
                type="button"
                wire:click="startTrip"
                wire:loading.attr="disabled"
                wire:target="startTrip" {{-- <-- KUNCI DI SINI: Mencegah tombol terkunci saat milih cluster --}}
                @disabled(!$selectedClusterId || $qtyCarried < 1)
                class="w-full py-4 rounded-xl font-bold text-lg transition-all
                    {{ ($selectedClusterId && $qtyCarried >= 1)
                        ? 'bg-amber-600 hover:bg-amber-700 text-white shadow-md active:scale-[0.98]'
                        : 'bg-slate-200 text-slate-400 cursor-not-allowed' }}
                    disabled:opacity-60 disabled:cursor-not-allowed"> {{-- <-- Tambahan utility class Tailwind untuk visual saat lock --}}

                {{-- State Teks Normal --}}
                <span wire:loading.remove wire:target="startTrip">
                    @if (!$selectedClusterId)
                        Pilih cluster dulu
                    @elseif ($qtyCarried < 1)
                        Isi jumlah mika dulu
                    @else
                        Mulai Trip Sekarang →
                    @endif
                </span>

                {{-- State Teks Saat Loading Transaksi --}}
                <span wire:loading wire:target="startTrip" class="inline-flex items-center gap-2">
                    <svg class="animate-spin h-5 w-5 text-white" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Memproses Trip...
                </span>
            </button>
        </div>
    @endif
</div>
