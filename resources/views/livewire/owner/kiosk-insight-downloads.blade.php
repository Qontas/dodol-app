<div class="max-w-4xl mx-auto space-y-6">
    <div>
        <a href="{{ auth()->user()->isSuperAdmin() ? '/admin/kiosks' : '/owner-panel/kiosks' }}" class="text-sm text-amber-800 underline">← Manajemen Kios</a>
        <h1 class="mt-4 text-2xl font-semibold text-slate-900">Unduh Laporan Kedai</h1>
        <p class="mt-2 text-slate-600">Seluruh riwayat kedai, rapor konsinyasi, dan saran tindakan. Rekomendasi tidak mengubah jatah atau data bisnis.</p>
    </div>
    <section class="rounded-lg border border-slate-200 bg-white p-5 space-y-4">
        @if (auth()->user()->isSuperAdmin())
            <div>
                <label for="report-owner" class="block font-medium text-slate-800">Owner</label>
                <select id="report-owner" wire:model="ownerId" class="mt-1 w-full rounded-md border-slate-300">
                    <option value="">Semua owner</option>
                    @foreach ($owners as $owner)
                        <option value="{{ $owner->id }}">{{ $owner->business_name ?: $owner->name }}</option>
                    @endforeach
                </select>
                @error('ownerId') <p class="text-red-700">{{ $message }}</p> @enderror
            </div>
        @endif
        <div class="grid gap-3 sm:grid-cols-3">
            <button type="button" wire:click="generate('excel')" wire:loading.attr="disabled" class="rounded-md bg-amber-700 px-4 py-3 font-semibold text-white disabled:opacity-50">Export Excel</button>
            <button type="button" wire:click="generate('photos')" wire:loading.attr="disabled" class="rounded-md border border-amber-700 px-4 py-3 font-semibold text-amber-900 disabled:opacity-50">Export Excel + Foto</button>
            <button type="button" wire:click="generate('pdf')" wire:loading.attr="disabled" class="rounded-md border border-amber-700 px-4 py-3 font-semibold text-amber-900 disabled:opacity-50">Export PDF</button>
        </div>
        <p class="text-sm text-slate-500">Excel tanpa foto disiapkan langsung. Export dengan foto diproses bertahap; biarkan halaman ini terbuka. Foto yang rusak tidak menggagalkan laporan.</p>
        <p wire:loading class="text-sm text-slate-700" role="status">Sedang memproses bagian laporan…</p>
    </section>
    @if ($exportId)
        <section @if (! ($progress['ready'] ?? false) && ! ($progress['failed'] ?? false)) wire:poll.1s="advance" @endif class="rounded-lg border border-slate-200 bg-white p-5" aria-live="polite">
            <p class="font-medium text-slate-800">{{ $progress['message'] ?? 'Menyiapkan laporan…' }}</p>
            @if ($progress['ready'] ?? false)
                <a href="{{ route('kiosk-insights.download', ['export' => $exportId]) }}" data-force-download class="mt-4 inline-block rounded-md bg-amber-700 px-5 py-3 font-semibold text-white">Unduh Laporan</a>
            @endif
            <p class="mt-3 text-sm text-slate-500">File privat, hanya dapat diunduh akun pembuatnya. Kedaluwarsa 24 jam sejak dibuat; setelah itu buat laporan baru.</p>
        </section>
    @endif
</div>
