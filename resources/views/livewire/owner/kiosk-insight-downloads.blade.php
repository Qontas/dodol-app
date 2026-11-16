<div class="max-w-4xl mx-auto space-y-6">
    <div>
        <a href="{{ $back['url'] }}" class="text-sm text-amber-800 underline">{{ $back['label'] }}</a>
        <h1 class="mt-4 text-2xl font-semibold text-slate-900">Unduh Laporan Kedai</h1>
        <p class="mt-2 text-slate-600">Seluruh riwayat kedai, rapor konsinyasi, dan saran tindakan. Rekomendasi tidak mengubah jatah atau data bisnis.</p>
    </div>
    <section class="rounded-lg border border-slate-200 bg-white p-5 space-y-4">
        @if (auth()->user()->isSuperAdmin())
            <div>
                <label for="report-owner" class="block font-medium text-slate-800">Owner</label>
                <select id="report-owner" wire:model="ownerId" class="kiosk-insight-select mt-1 w-full rounded-md border-slate-300">
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
        {{-- Ketahanan error (hotfix 16 Nov 2026): polling digerakkan Alpine, bukan wire:poll, supaya satu request yang
             gagal (500/419/jaringan/timeout) TIDAK memunculkan popup browser "page expired" dan polling berhenti
             rapi. Progres tersimpan di server (state.json) → "Lanjutkan" meneruskan dari langkah terakhir. --}}
        <section wire:key="export-{{ $exportId }}" @if (! ($progress['ready'] ?? false) && ! ($progress['failed'] ?? false)) data-kiosk-insight-active @endif class="rounded-lg border border-slate-200 bg-white p-5" aria-live="polite"
            x-data="{
                failed: false, status: null, gen: 0,
                remember() { const url = new URL(location.href); url.searchParams.set('export', @js($exportId)); history.replaceState(null, '', url); },
                destroy() { this.gen++; },
                done() { return $wire.progress?.ready || $wire.progress?.failed; },
                start() { this.failed = false; this.run(++this.gen); },
                async run(gen) {
                    while (gen === this.gen && ! this.failed && ! this.done()) {
                        await new Promise(r => setTimeout(r, 1000));
                        if (gen !== this.gen || this.failed || this.done()) return;
                        try { await $wire.advance(); } catch (e) { if (gen === this.gen) { this.failed = true; } return; }
                    }
                },
                resume() { if (this.status === 419) { window.location.reload(); return; } this.start(); },
                restart() { const url = new URL(location.href); url.searchParams.delete('export'); window.location.assign(url); },
            }"
            x-init="remember(); start()"
            x-on:kiosk-insight-request-failed.window="if (! done()) { gen++; failed = true; status = $event.detail.status; }">
            <p class="font-medium text-slate-800">{{ $progress['message'] ?? 'Menyiapkan laporan…' }}</p>
            @if ($progress['ready'] ?? false)
                <a href="{{ route('kiosk-insights.download', ['export' => $exportId]) }}" data-force-download class="mt-4 inline-block rounded-md bg-amber-700 px-5 py-3 font-semibold text-white">Unduh Laporan</a>
            @endif
            @if (! ($progress['ready'] ?? false) && ! ($progress['failed'] ?? false))
                <div x-show="failed" x-cloak class="mt-4 rounded-md border border-amber-300 bg-amber-50 p-4" role="alert">
                    <p class="font-medium text-amber-900">Proses terputus sebentar<span x-show="status" x-text="' (kode ' + status + ')'"></span>. Kemajuan laporan masih tersimpan.</p>
                    <p x-show="status === 419" class="mt-1 text-sm text-amber-900">Lanjutkan akan menyegarkan sesi halaman dan membuka progres tersimpan. Jika diminta, masuk kembali dengan akun yang sama.</p>
                    <div class="mt-3 flex flex-wrap gap-3">
                        <button type="button" x-on:click="resume()" class="rounded-md bg-amber-700 px-4 py-2 font-semibold text-white">Lanjutkan</button>
                        <button type="button" x-on:click="restart()" class="rounded-md border border-amber-700 px-4 py-2 font-semibold text-amber-900">Mulai ulang</button>
                    </div>
                </div>
            @endif
            @if ($progress['failed'] ?? false)
                <button type="button" x-on:click="restart()" class="mt-4 rounded-md border border-amber-700 px-4 py-2 font-semibold text-amber-900">Mulai ulang</button>
            @endif
            <p class="mt-3 text-sm text-slate-500">File privat, hanya dapat diunduh akun pembuatnya. Kedaluwarsa 24 jam sejak dibuat; setelah itu buat laporan baru.</p>
        </section>
    @endif
    @script
    <script>
        // Tangkap kegagalan request Livewire HANYA saat ada export berjalan: jangan tampilkan modal/confirm
        // bawaan ("This page has expired"), tampilkan pesan + tombol di halaman. Kegagalan di luar export
        // tetap ditangani Livewire seperti biasa.
        (() => {
        if (! window.__kioskInsightHook) {
            window.__kioskInsightHook = true;
            Livewire.hook('request', ({ payload, fail }) => {
                const body = typeof payload === 'string' ? JSON.parse(payload) : payload;
                const isReportRequest = body.components?.some(part => {
                    const snapshot = typeof part.snapshot === 'string' ? JSON.parse(part.snapshot) : part.snapshot;
                    return snapshot?.memo?.name === 'owner.kiosk-insight-downloads';
                });
                fail(({ status, preventDefault }) => {
                    if (isReportRequest && document.querySelector('[data-kiosk-insight-active]')) {
                        preventDefault();
                        window.dispatchEvent(new CustomEvent('kiosk-insight-request-failed', { detail: { status } }));
                    }
                });
            });
        }
        })();
    </script>
    @endscript
</div>
