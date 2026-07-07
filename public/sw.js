/**
 * Service Worker — Cemilan Qontas (dodol-app)
 *
 * Strategi (v5 — aman-by-default + validasi HTTP-cache eksplisit):
 *  - App shell statis (ikon, manifest, offline page) di-precache saat install.
 *  - Aset Vite ber-hash (/build/assets/*) di-cache RUNTIME (cache-on-fetch),
 *    JANGAN di-hardcode karena namanya berganti tiap build.
 *  - Aset "berat tapi immutable" (gambar/ikon/font, by extension) -> CACHE-FIRST.
 *    Nama file baru = URL baru (foto kios upload baru, ikon marker Leaflet, dst),
 *    jadi aman cache selamanya — ini yang bikin operator lapangan tetap sat-set.
 *  - SEMUA SISANYA (default) -> NETWORK-FIRST. v4 pakai allowlist eksplisit
 *    (path Filament/vendor/leaflet-map-picker.js) dengan fallback cache-first utk
 *    path tak dikenal — TERBUKTI rawan: file baru yang lupa didaftarkan otomatis
 *    basi-selamanya (kejadian 2x: CSS Filament, lalu leaflet-map-picker.js). v5
 *    membalik default jadi network-first: lupa daftarin path baru = tetap FRESH
 *    (aman), bukan basi (bug).
 *  - networkFirst() pakai fetch(request, {cache:'no-cache'}) — PAKSA validasi ke
 *    server via If-None-Match/If-Modified-Since, BUKAN percaya heuristic-freshness
 *    HTTP cache browser sendiri (server tak kirim header Cache-Control eksplisit,
 *    jadi browser bisa diam-diam anggap "masih fresh" tanpa nanya server sama
 *    sekali — celah asli kenapa "sudah network-first" tetap pernah basi). Kalau
 *    file BENAR belum berubah, server balas 304 Not Modified (badan kosong, cepat)
 *    — BUKAN 'reload' (itu skip validator sama sekali, selalu full re-download,
 *    lebih berat di sinyal jelek operator).
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

const CACHE_NAME = 'dodol-v5';

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

// Gambar/font by extension: berat (byte-wise) tapi jarang berubah, dan kalaupun
// berubah (foto kios upload ulang) nama file BARU (bukan overwrite URL lama) —
// aman cache-first selamanya. Ini yang menjaga peta/foto/ikon tetap instan di
// sinyal jelek, TANPA mengorbankan kebenaran data (karena URL-nya sendiri unik).
const IMMUTABLE_ASSET_PATTERN = /\.(png|jpe?g|gif|webp|svg|ico|woff2?|ttf|eot)$/i;

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
                // CSS Filament basi (bug v2) + halaman ter-auth lama (bug v1) di device,
                // dan (v5) entry lama yang strateginya berubah (mis. sebelumnya
                // cache-first-by-default, sekarang harus network-first).
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

    // --- IMMUTABLE -> cache-first (kecepatan, aman krn URL unik/tetap) ---

    // Aset build Vite ber-hash -> cache-first (immutable, aman & cepat).
    if (url.pathname.startsWith('/build/')) {
        event.respondWith(cacheFirst(request));
        return;
    }

    // App shell yang sudah di-precache saat install.
    if (APP_SHELL.includes(url.pathname)) {
        event.respondWith(cacheFirst(request));
        return;
    }

    // Gambar/font (foto kios, ikon marker Leaflet, dll) — lihat komentar
    // IMMUTABLE_ASSET_PATTERN di atas.
    if (IMMUTABLE_ASSET_PATTERN.test(url.pathname)) {
        event.respondWith(cacheFirst(request));
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

    // --- DEFAULT (aman-by-default) -> network-first ---
    // Semua sisanya: JS/CSS Filament, leaflet.js/css, leaflet-map-picker.js, DAN
    // path baru apa pun yang belum sempat "didaftarkan" — semuanya otomatis
    // network-first. Beda dari aset immutable di atas, path-path ini nama
    // filenya TETAP (paling banter dibedakan ?v=versi yang tak selalu naik saat
    // konten berubah), jadi kalau lupa dikategorikan, DEFAULT-nya sekarang aman
    // (fresh), bukan basi (cache-first selamanya — bug 2x sebelumnya).
    event.respondWith(networkFirst(request));
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
 *
 * cache:'no-cache' (BUKAN 'reload'): paksa browser VALIDASI ke server (kirim
 * If-None-Match/If-Modified-Since) alih-alih diam-diam percaya heuristic-freshness
 * HTTP cache-nya sendiri (server tak kirim Cache-Control eksplisit, jadi tanpa ini
 * browser BOLEH anggap respons lama "masih fresh" tanpa pernah nanya server —
 * itulah kenapa "sudah network-first" tetap bisa basi). Kalau server balas 304,
 * fetch() di sini tetap dapat body dari cache (cepat, hemat data lapangan) —
 * BEDA dari 'reload' yang skip validator sama sekali & selalu full re-download.
 */
async function networkFirst(request) {
    const cache = await caches.open(CACHE_NAME);
    try {
        const response = await fetch(request, { cache: 'no-cache' });
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
