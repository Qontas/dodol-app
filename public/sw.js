/**
 * Service Worker — Cemilan Qontas (dodol-app)
 *
 * Strategi (v4 — multi-tenant safe):
 *  - App shell statis (ikon, manifest, offline page) di-precache saat install.
 *  - Aset Vite ber-hash (/build/assets/*) di-cache RUNTIME (cache-on-fetch),
 *    JANGAN di-hardcode karena namanya berganti tiap build.
 *  - Aset Filament/plugin/Leaflet (nama file TETAP, cuma ?v=versi) pakai
 *    NETWORK-FIRST — online SELALU fresh, cache cuma fallback offline. (v2: kena bug
 *    CSS Filament basi nyangkut di device → layout panel owner/admin rusak. v3 sempat
 *    pakai SWR tapi masih lag 1 load saat versi sama konten beda → naik ke network-first
 *    biar reload langsung benar saat online.)
 *  - Halaman HTML ter-AUTENTIKASI (dashboard owner/operator/admin) TIDAK PERNAH
 *    di-cache. App ini multi-tenant: nge-cache halaman per-URL tanpa bedakan user
 *    bikin akun B melihat dashboard akun A. Navigasi = NETWORK-ONLY.
 *  - Saat offline, navigasi hanya jatuh ke offline.html generik (pesan "offline"),
 *    BUKAN menyajikan ulang dashboard lama.
 *  - POST & request non-GET (Livewire update, form) TIDAK pernah disentuh SW.
 *  - Request Livewire (/livewire/*) di-BYPASS total agar tidak menambah latency.
 */

const CACHE_NAME = 'dodol-v4';

// App shell statis & non-hash. Aman di-precache karena URL-nya tetap & tidak per-user.
const APP_SHELL = [
    '/offline.html',
    '/manifest.webmanifest',
    '/icon-192.png',
    '/icon-512.png',
    '/icon-maskable-192.png',
    '/icon-maskable-512.png',
    '/apple-touch-icon.png',
    '/favicon.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) =>
            // addAll gagal total kalau satu file 404; pakai per-file agar tahan banting.
            Promise.allSettled(APP_SHELL.map((url) => cache.add(url)))
        ).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(
                // Buang SEMUA cache lama (≠ CACHE_NAME). Ini sekaligus membersihkan
                // CSS Filament basi (bug v2) + halaman ter-auth lama (bug v1) di device.
                keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))
            )
        ).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    // Hanya tangani GET. POST / Livewire update / form -> biarkan ke network.
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    // Hanya origin sendiri. Font/CDN eksternal & API eksternal biarkan default browser.
    if (url.origin !== self.location.origin) {
        return;
    }

    // Request Livewire -> BYPASS total (jangan diproses SW, biar tidak nambah delay).
    if (url.pathname.startsWith('/livewire/')) {
        return;
    }

    // Endpoint token CSRF -> BYPASS total. JANGAN pernah di-cache: token usang dari
    // cache = 419. Selalu ambil dari server (sesi terkini).
    if (url.pathname === '/csrf-token') {
        return;
    }

    // Aset build Vite ber-hash -> cache-first (immutable, aman & cepat).
    if (url.pathname.startsWith('/build/')) {
        event.respondWith(cacheFirst(request));
        return;
    }

    // Aset Filament / plugin Filament / Leaflet -> NETWORK-FIRST.
    // Beda dari /build/ (nama file ber-hash), aset ini nama filenya TETAP dan
    // cuma dibedakan query ?v=<versi>. Versi itu HANYA naik saat versi paket
    // naik, TIDAK saat `filament:assets` republish konten di versi yang sama.
    // cacheFirst (cache selamanya) maupun SWR (lag 1 load) bisa menyajikan CSS
    // basi → HTML baru ketemu CSS lama → layout panel rusak. Network-first:
    // saat ONLINE selalu ambil fresh dari server (reload langsung benar); cache
    // dipakai HANYA sebagai fallback kalau offline. Panel owner/admin = alat
    // online, jadi tak butuh kecepatan cache-first untuk aset ini.
    if (
        /^\/(css|js)\/filament\//.test(url.pathname) ||
        /^\/(css|js)\/dotswan\//.test(url.pathname) ||
        url.pathname.startsWith('/vendor/')
    ) {
        event.respondWith(networkFirst(request));
        return;
    }

    // Halaman HTML -> NETWORK-ONLY, tidak pernah dicache.
    // Deteksi pakai mode 'navigate' DAN header Accept: text/html, karena
    // wire:navigate mengambil halaman via fetch() biasa (mode 'cors', bukan
    // 'navigate'). Tanpa cek Accept, halaman ter-auth dari wire:navigate akan
    // lolos ke cacheFirst dan ikut tersimpan (kebocoran multi-tenant + data basi).
    const accept = request.headers.get('accept') || '';
    if (request.mode === 'navigate' || accept.includes('text/html')) {
        event.respondWith(networkOnlyPage(request));
        return;
    }

    // Aset statis lain (ikon, manifest, gambar) -> cache-first.
    event.respondWith(cacheFirst(request));
});

/** Cache-first: pakai cache kalau ada, kalau tidak ambil network lalu simpan. */
async function cacheFirst(request) {
    const cached = await caches.match(request);
    if (cached) {
        return cached;
    }
    try {
        const response = await fetch(request);
        if (response && response.ok && response.type === 'basic') {
            const cache = await caches.open(CACHE_NAME);
            cache.put(request, response.clone());
        }
        return response;
    } catch (err) {
        return cached || Response.error();
    }
}

/**
 * Network-first: SELALU coba network dulu (online = fresh), simpan ke cache untuk
 * fallback offline. Kalau network gagal (offline), baru pakai cache. Tidak pernah
 * menyajikan konten basi selama device online → cocok untuk aset yang nama filenya
 * tidak ber-hash (CSS/JS Filament) yang bisa berubah di bawah ?v=versi yang sama.
 */
async function networkFirst(request) {
    const cache = await caches.open(CACHE_NAME);
    try {
        const response = await fetch(request);
        if (response && response.ok && response.type === 'basic') {
            cache.put(request, response.clone());
        }
        return response;
    } catch (err) {
        const cached = await cache.match(request);
        return cached || Response.error();
    }
}

/**
 * Network-only untuk navigasi halaman.
 * - SELALU ambil dari network (tidak pernah baca/tulis cache halaman).
 * - Kalau benar-benar offline, tampilkan offline.html generik sebagai pesan,
 *   BUKAN dashboard lama. Tidak ada kebocoran data antar-user.
 */
async function networkOnlyPage(request) {
    try {
        return await fetch(request);
    } catch (err) {
        const offline = await caches.match('/offline.html');
        return offline || Response.error();
    }
}
