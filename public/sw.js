/**
 * Service Worker — Cemilan Qontas (dodol-app)
 *
 * Strategi:
 *  - App shell minimal di-precache saat install (ikon, manifest, offline page).
 *  - Aset Vite ber-hash (/build/assets/*) di-cache RUNTIME (cache-on-fetch),
 *    JANGAN di-hardcode karena namanya berganti tiap build.
 *  - Navigasi HTML & data: NETWORK-FIRST, fallback ke cache / offline.html
 *    hanya saat benar-benar offline (biar data kios/settlement selalu fresh).
 *  - POST & request non-GET (mis. Livewire update) TIDAK pernah di-cache.
 */

const CACHE_NAME = 'dodol-v1';

// App shell statis & non-hash. Aman di-precache karena URL-nya tetap.
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

    // Hanya origin sendiri. Font/CDN eksternal biarkan default browser.
    if (url.origin !== self.location.origin) {
        return;
    }

    // Aset build Vite ber-hash -> cache-first (immutable, aman & cepat).
    if (url.pathname.startsWith('/build/')) {
        event.respondWith(cacheFirst(request));
        return;
    }

    // Navigasi halaman (HTML) -> network-first, fallback offline.html.
    if (request.mode === 'navigate') {
        event.respondWith(networkFirstPage(request));
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

/** Network-first untuk navigasi: selalu coba network dulu, fallback ke cache lalu offline.html. */
async function networkFirstPage(request) {
    try {
        const response = await fetch(request);
        // Simpan salinan shell terakhir yang sukses (untuk fallback saat offline).
        if (response && response.ok) {
            const cache = await caches.open(CACHE_NAME);
            cache.put(request, response.clone());
        }
        return response;
    } catch (err) {
        const cached = await caches.match(request);
        if (cached) {
            return cached;
        }
        const offline = await caches.match('/offline.html');
        return offline || Response.error();
    }
}
