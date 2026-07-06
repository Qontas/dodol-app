{{--
    Fix map-picker abu-abu di mobile.
    Akar masalah: Leaflet menghitung ukuran container SEBELUM layout mobile
    (section collapsible + retina) selesai settle, jadi container di-size salah
    dan tiles cuma nutup sebagian → sisanya grey. Paket vendor juga membongkar &
    membangun ulang peta lewat IntersectionObserver saat peta masuk/keluar layar
    (mis. di-scroll ke section Lokasi, atau section dibuka dari keadaan collapse).

    Solusi (tanpa ubah paket vendor): paksa Leaflet hitung ulang ukuran container.
    Leaflet default `trackResize: true` → window 'resize' memicu invalidateSize
    internal → tiles diisi ulang penuh.

    Pemicu (dibuat tahan terhadap bongkar-pasang peta oleh vendor):
    1. Beberapa dispatch resize bertahap setelah render (nunggu layout settle).
    2. Dispatch resize saat scroll (throttled) — scroll = pemicu peta dibuat ulang.
    3. Scan berkala: begitu lebar .leaflet-container berubah dari 0 → positif
       (peta baru dibuat / section Lokasi dibuka), dispatch resize sekali.

    Efek samping yang sama juga dipakai fitur "Loncat ke lokasi" (paste link/
    koordinat Google Maps): kalau peta belum ke-scroll ke layar saat tombol
    diklik, IntersectionObserver vendor sudah membongkar this.map (null) →
    event 'refreshMap' bawaan paket crash "Cannot read properties of null
    (reading 'flyTo')". Jadi saat menerima 'kiosk-location-jumped' dari action
    di KioskResource, scroll peta ke layar dulu (memicu observer membangun
    ulang this.map) baru dispatch 'refreshMap' setelah delay.
--}}
<div
    wire:ignore
    x-data="{
        lastWidth: 0,
        scrollTick: false,
        timer: null,
        bump() { window.dispatchEvent(new Event('resize')); },
        currentWidth() {
            const form = this.$root.closest('form') ?? document;
            const el = form.querySelector('.leaflet-container');
            return el ? el.getBoundingClientRect().width : 0;
        },
        scan() {
            const w = this.currentWidth();
            if (w > 0 && w !== this.lastWidth) { this.lastWidth = w; this.bump(); }
            else if (w === 0) { this.lastWidth = 0; }
        },
        onScroll() {
            if (this.scrollTick) return;
            this.scrollTick = true;
            requestAnimationFrame(() => { this.bump(); this.scrollTick = false; });
        },
        jumpToMapLocation() {
            const form = this.$root.closest('form') ?? document;
            const mapEl = form.querySelector('[x-data^=\'mapPicker\']');
            if (mapEl) { mapEl.scrollIntoView({ behavior: 'instant', block: 'center' }); }
            setTimeout(() => Livewire.dispatch('refreshMap'), 500);
        },
        start() {
            [150, 400, 800].forEach((t) => setTimeout(() => this.bump(), t));
            this.timer = setInterval(() => this.scan(), 500);
            window.addEventListener('scroll', () => this.onScroll(), { passive: true, capture: true });
        },
        stop() { if (this.timer) clearInterval(this.timer); }
    }"
    x-init="start()"
    x-on:livewire:navigating.window="stop()"
    x-on:kiosk-location-jumped.window="jumpToMapLocation()"
    style="display:none"
></div>
