{{-- STOP TITIPAN (cut off kios) — dipakai di Opsi Khusus & aksi Cek Saja.
     Reuse method Livewire stopKios() + state showStopConfirm/stopReason. --}}
<div class="border-t border-slate-100 pt-3 mt-3">
    @if(! $showStopConfirm)
        <button type="button" wire:click="$set('showStopConfirm', true)"
            class="w-full text-left p-3 rounded-xl border border-red-200 bg-red-50 text-sm font-medium text-red-700 active:bg-red-100">
            🚫 Stop Titipan Kios Ini
        </button>
    @else
        <div class="space-y-3">
            <p class="text-sm font-bold text-red-700">Alasan Stop:</p>
            @foreach([
                'pemilik_minta_stop' => '🙏 Pemilik minta berhenti sementara',
                'tutup_permanen' => '🔒 Kedai tutup permanen',
                'kurang_laku' => '📉 Penjualan kurang jalan',
                'pindah_lokasi' => '📍 Pindah lokasi',
                'lainnya' => '📝 Alasan lain',
            ] as $val => $label)
                <label class="flex items-center gap-2 p-3 rounded-xl border cursor-pointer {{ $stopReason === $val ? 'border-red-400 bg-red-50' : 'border-slate-200' }}">
                    <input type="radio" wire:model.live="stopReason" value="{{ $val }}" class="sr-only">
                    <span class="text-sm">{{ $label }}</span>
                </label>
            @endforeach
            @error('stopReason')
                <p class="text-xs text-red-600">{{ $message }}</p>
            @enderror
            @if($pendingDelivery)
                <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-xs text-amber-800">
                    ⚠️ Kios ini masih punya titipan aktif. Selesaikan tagihan dulu sebelum stop.
                </div>
            @endif
            <div class="flex gap-2">
                <button type="button" wire:click="$set('showStopConfirm', false)"
                    class="flex-1 py-2 rounded-xl border border-slate-200 text-sm text-slate-600 active:bg-slate-50">
                    Batal
                </button>
                <button type="button" wire:click="stopKios" wire:loading.attr="disabled" wire:target="stopKios"
                    @disabled((bool) $pendingDelivery)
                    class="flex-1 py-2 rounded-xl bg-red-600 text-white text-sm font-bold active:bg-red-700 disabled:opacity-60">
                    Konfirmasi Stop
                </button>
            </div>
        </div>
    @endif
</div>
