// Leaflet map picker hand-rolled utk form OWNER (KioskResource) — pengganti
// dotswan/filament-map-picker. Peta operator (create-kiosk.blade.php) sudah
// pakai pola sama (L.map + setView langsung) dan terbukti stabil; dotswan
// sempat 3x jadi sumber bug (grey-tile invalidateSize, lalu refreshMap tak
// pernah kepanggil krn IntersectionObserver-nya sendiri). Di sini TIDAK ada
// observer pembongkar peta — map dibuat sekali, "Loncat ke lokasi" langsung
// panggil map.setView(), tanpa perantara.
//
// RIWAYAT BUG (26 Okt 2026, brief "peta kosong + GPS meleset"): file ini
// sebelumnya mendefinisikan window.leafletMapPicker HANYA di dalam
// document.addEventListener('DOMContentLoaded', ...). DOMContentLoaded
// cuma terjadi SEKALI per load browser sungguhan — kalau script ini pernah
// disisipkan ulang TANPA full reload (mis. wire:navigate di masa depan),
// window.leafletMapPicker tak akan pernah terdefinisi ulang dan x-data pada
// div peta gagal (ReferenceError). Dibungkus IIFE + cek document.readyState:
// kalau DOM SUDAH siap saat script ini jalan, definisikan LANGSUNG; kalau
// belum, tunggu DOMContentLoaded sekali seperti biasa. Idempotent utk kedua
// kasus, tanpa mengubah urutan eksekusi relatif thd x-init="init()" pada
// load penuh yang sudah terbukti jalan (lihat NEXT_SESSION.md).
(function () {
    function defineLeafletMapPicker() {
        window.leafletMapPicker = function ($wire, config) {
            return {
                map: null,
                marker: null,

                // State tombol prominent "Pakai Lokasi Saya (GPS)" (dirender di blade,
                // reaktif Alpine). showGpsButton ikut config yg sama dgn kontrol peta kecil.
                showGpsButton: !!config.showMyLocationButton,
                gpsLoading: false,
                gpsSearchingText: '',
                gpsError: '',
                // Akurasi bacaan GPS terbaik (meter) utk badge "Akurasi ±X meter" di blade.
                // null = belum ada bacaan GPS (lokasi default/manual/ditolak).
                gpsAccuracy: null,
                // Band 31-100m: pin dipasang TAPI wajib konfirmasi, koordinat BELUM
                // tersimpan sampai tombol "Pakai lokasi ini" dipencet. {lat,lng,accuracy}.
                gpsPending: null,
                accuracyCircle: null,
                gpsTracker: null,
                tileError: false,

                getCoordinates() {
                    const state = $wire.get(config.statePath) ?? {};
                    const hasCoords = state && state.lat !== null && state.lat !== undefined
                        && state.lng !== null && state.lng !== undefined;

                    return hasCoords
                        ? { lat: Number(state.lat), lng: Number(state.lng) }
                        : { lat: config.default.lat, lng: config.default.lng };
                },

                writeCoordinates(lat, lng) {
                    $wire.set(config.statePath, { lat, lng });
                },

                init() {
                    this.config = config;
                    const el = this.$refs.map;

                    // Livewire kadang commit ulang segera setelah load awal (mis. lazy/polling
                    // komponen lain di halaman yang sama) yang bisa memicu x-init dobel walau
                    // node DOM-nya TIDAK berubah (wire:ignore melindungi children, bukan proses
                    // init Alpine itu sendiri). Leaflet menandai node lewat `_leaflet_id` begitu
                    // L.map() sukses sekali — kalau sudah ada, ini panggilan kedua: no-op, biar
                    // instance PERTAMA (yang sudah pegang listener asli) tetap satu-satunya map.
                    if (el._leaflet_id) {
                        return;
                    }

                    const start = this.getCoordinates();

                    this.map = L.map(el, {
                        zoomControl: config.showZoomControl,
                        maxZoom: config.maxZoom,
                    }).setView([start.lat, start.lng], config.zoom);

                    // Jaring pengaman kedua (di luar ResizeObserver di bawah): container
                    // bisa masih 0px sesaat tepat saat L.map() dipanggil (section baru
                    // dibuka/transisi layout) -> paksa ukur ulang begitu frame berikutnya.
                    requestAnimationFrame(() => this.map && this.map.invalidateSize());

                    // maxNativeZoom = maxZoom: cegah over-zoom ke level tanpa tile
                    // (akar "grey saat zoom kuat", sama fix dgn peta operator). Tanpa
                    // `subdomains`: tilesUrl standar (tile.openstreetmap.org) host tunggal,
                    // tak pakai placeholder {s} (beda dari CARTO yang dibuang 20 Okt 2026
                    // krn skrg wajib API key di zoom dekat - lihat KioskResource).
                    const tiles = L.tileLayer(config.tilesUrl, {
                        attribution: config.attribution,
                        maxZoom: config.maxZoom,
                        maxNativeZoom: config.maxZoom,
                    }).addTo(this.map);

                    // Tile gagal muat (CDN diblokir jaringan/kartu SIM tertentu, dll) ->
                    // jangan diamkan jadi kotak abu-abu misterius. Sama pesan dgn peta
                    // operator ("Gambar peta gagal dimuat"), tampil sekali saja.
                    let tileErrorShown = false;
                    tiles.on('tileerror', () => {
                        if (tileErrorShown) return;
                        tileErrorShown = true;
                        this.tileError = true;
                    });

                    this.marker = L.marker([start.lat, start.lng], {
                        draggable: config.draggable,
                        icon: this.markerIcon(),
                    }).addTo(this.map);

                    if (config.draggable) {
                        this.marker.on('dragend', () => {
                            const pos = this.marker.getLatLng();
                            // Pin digeser manual -> koreksi yang disengaja, bukan lagi bacaan GPS
                            // mentah. Lepas badge/lingkaran akurasi GPS lama.
                            this.clearGpsAccuracyUi();
                            this.writeCoordinates(pos.lat, pos.lng);
                        });
                    }

                    if (config.clickable) {
                        this.map.on('click', (e) => {
                            this.clearGpsAccuracyUi();
                            this.marker.setLatLng(e.latlng);
                            this.writeCoordinates(e.latlng.lat, e.latlng.lng);
                        });
                    }

                    // showMyLocationButton kini dirender sebagai tombol PROMINENT di blade
                    // (di atas peta, sejajar tombol operator) — bukan lagi kontrol ikon kecil
                    // di pojok peta, biar owner mudah menemukannya. addGpsControl() dipertahankan
                    // (dipakai method useMyLocation yang sama) tapi tak dipasang ke peta lagi.

                    if (config.showFullscreenControl) {
                        this.addFullscreenControl();
                    }

                    // Ganti workaround IntersectionObserver dotswan (scan 500ms + scroll
                    // listener + dispatch resize bertahap): ResizeObserver bawaan browser,
                    // invalidateSize tiap kali ukuran container BENERAN berubah (section
                    // Lokasi dibuka/ditutup, layout mobile settle, sidebar toggle, dst).
                    new ResizeObserver(() => this.map.invalidateSize()).observe(el);

                    window.addEventListener('kiosk-location-jumped', () => this.jumpToLocation());
                },

                jumpToLocation() {
                    // Loncat ke lokasi tempel-link/koordinat -> manual, bukan bacaan GPS.
                    this.stopGpsWatch();
                    this.clearGpsAccuracyUi();
                    const coords = this.getCoordinates();
                    this.map.setView([coords.lat, coords.lng], Math.max(this.map.getZoom(), 15));
                    this.marker.setLatLng([coords.lat, coords.lng]);
                },

                markerIcon() {
                    const svg = `<svg xmlns="http://www.w3.org/2000/svg" fill="${config.markerColor}" width="36" height="36" viewBox="0 0 24 24"><path d="M12 0c-4.198 0-8 3.403-8 7.602 0 4.198 3.469 9.21 8 16.398 4.531-7.188 8-12.2 8-16.398 0-4.199-3.801-7.602-8-7.602zm0 11c-1.657 0-3-1.343-3-3s1.343-3 3-3 3 1.343 3 3-1.343 3-3 3z"/></svg>`;

                    return L.divIcon({
                        html: svg,
                        className: '',
                        iconSize: [36, 36],
                        iconAnchor: [18, 36],
                    });
                },

                makeControlButton(position, title, innerHtml, onClick) {
                    const control = L.control({ position });
                    control.onAdd = () => {
                        const div = L.DomUtil.create('div', 'leaflet-bar leaflet-control');
                        const a = L.DomUtil.create('a', '', div);
                        a.href = '#';
                        a.title = title;
                        a.innerHTML = innerHtml;
                        a.style.display = 'flex';
                        a.style.alignItems = 'center';
                        a.style.justifyContent = 'center';
                        L.DomEvent.disableClickPropagation(div);
                        L.DomEvent.on(a, 'click', (e) => {
                            L.DomEvent.preventDefault(e);
                            onClick();
                        });
                        return div;
                    };
                    control.addTo(this.map);
                },

                addGpsControl() {
                    const icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M12 0C8.25 0 5 3.25 5 7c0 5.25 7 13 7 13s7-7.75 7-13c0-3.75-3.25-7-7-7zm0 10c-1.66 0-3-1.34-3-3s1.34-3 3-3 3 1.34 3 3-1.34 3-3 3zm0-5c-1.11 0-2 .89-2 2s.89 2 2 2 2-.89 2-2-.89-2-2-2z"/></svg>';
                    this.makeControlButton('topright', 'Pakai lokasi saya', icon, () => this.useMyLocation());
                },

                // Kebijakan GPS akurat 26 Okt 2026 — SATU sumber kebenaran dipakai juga oleh
                // peta operator (create-kiosk.blade.php) & "Urutkan Jarak" (active-trip.blade.php),
                // lihat public/js/gps-accuracy.js. HANYA enableHighAccuracy:true+maximumAge:0
                // (TANPA fallback akurasi rendah — itulah fix 21 Okt 2026 yang tak pernah lolos
                // uji lapangan), watchPosition maks 20 detik, berhenti lebih awal begitu ≤15m.
                // Band HASIL AKHIR: ≤30m otomatis, 31-100m wajib konfirmasi, >100m tolak (JANGAN
                // pasang pin; >1000m dapat petunjuk izin "Perkiraan" Android).
                useMyLocation() {
                    this.gpsError = '';

                    if (!('geolocation' in navigator)) {
                        this.gpsError = 'Browser ini tidak mendukung GPS/lokasi.';
                        return;
                    }

                    this.stopGpsWatch();
                    this.clearGpsAccuracyUi();
                    this.gpsLoading = true;
                    this.gpsSearchingText = 'Mencari lokasi…';

                    this.gpsTracker = window.trackAccurateGps({
                        onUpdate: (best) => {
                            this.gpsSearchingText = `Mencari… akurasi ±${Math.round(best.accuracy)} m`;
                        },
                        onDone: (best) => {
                            this.gpsLoading = false;
                            this.gpsSearchingText = '';
                            if (!best) {
                                this.revertUnconfirmedMarker();
                                this.gpsError = 'Terlalu lama mencari lokasi. Sinyal GPS tidak didapat dalam 20 detik, coba lagi di tempat yang lebih terbuka.';
                                return;
                            }
                            this.applyGpsResult(best);
                        },
                        onError: (err) => {
                            this.gpsLoading = false;
                            this.gpsSearchingText = '';
                            this.revertUnconfirmedMarker();
                            if (err.code === 'PERMISSION_DENIED') {
                                this.gpsError = 'Izin lokasi ditolak. Aktifkan izin lokasi untuk situs ini di browser, lalu coba lagi.';
                            }
                        },
                    });
                },

                // Keputusan band dibuat SEKALI di sini setelah tracker berhenti (bukan tiap
                // update selagi mencari) — persis kebijakan brief 26 Okt 2026.
                applyGpsResult(best) {
                    const band = window.classifyGpsAccuracy(best.accuracy);
                    if (band === 'good') {
                        this.map.setView([best.lat, best.lng], 17);
                        this.marker.setLatLng([best.lat, best.lng]);
                        this.writeCoordinates(best.lat, best.lng);
                        this.updateGpsAccuracyUi(best.lat, best.lng, best.accuracy);
                        // Cegah tile abu-abu setelah recenter (container mungkin baru
                        // di-relayout) — sama fix dgn ResizeObserver invalidateSize.
                        setTimeout(() => this.map.invalidateSize(), 60);
                    } else if (band === 'confirm') {
                        this.gpsPending = best;
                        this.marker.setLatLng([best.lat, best.lng]); // visual saja - BELUM tersimpan
                        this.map.setView([best.lat, best.lng], 17);
                    } else {
                        // band 'bad' / 'very_bad' -> JANGAN pasang pin.
                        this.revertUnconfirmedMarker();
                        this.gpsError = window.gpsInaccurateMessage(best.accuracy);
                    }
                },

                // Tombol "Pakai lokasi ini (±X m)" pada band 31-100m — baru di titik ini
                // koordinat betulan tersimpan ke server.
                confirmGpsPending() {
                    if (!this.gpsPending) return;
                    const p = this.gpsPending;
                    this.gpsPending = null;
                    this.writeCoordinates(p.lat, p.lng);
                    this.updateGpsAccuracyUi(p.lat, p.lng, p.accuracy);
                    this.map.setView([p.lat, p.lng], 17);
                },

                // Tombol "Coba lagi" pada band 31-100m — batalkan pin yang belum tersimpan,
                // kembalikan pin ke koordinat tersimpan sebelumnya, lalu ulang pencarian.
                retryGps() {
                    this.gpsPending = null;
                    this.revertUnconfirmedMarker();
                    this.useMyLocation();
                },

                // Kembalikan pin visual ke koordinat yang TERSIMPAN (bukan bacaan GPS yang
                // dibatalkan/ditolak) — dipakai saat percobaan GPS gagal/ditolak supaya tidak
                // ada pin "mengambang" dari bacaan yang belum (atau tidak akan) dikonfirmasi.
                revertUnconfirmedMarker() {
                    const coords = this.getCoordinates();
                    this.marker.setLatLng([coords.lat, coords.lng]);
                },

                stopGpsWatch() {
                    if (this.gpsTracker) {
                        this.gpsTracker.stop();
                        this.gpsTracker = null;
                    }
                },

                // Badge "Akurasi ±X meter" (blade) + lingkaran radius akurasi di peta (L.circle).
                updateGpsAccuracyUi(lat, lng, accuracy) {
                    this.gpsAccuracy = Math.round(accuracy);
                    if (this.accuracyCircle) this.map.removeLayer(this.accuracyCircle);
                    this.accuracyCircle = L.circle([lat, lng], {
                        radius: accuracy,
                        color: '#3b82f6',
                        weight: 1,
                        fillColor: '#3b82f6',
                        fillOpacity: 0.12,
                    }).addTo(this.map);
                },

                clearGpsAccuracyUi() {
                    this.gpsAccuracy = null;
                    this.gpsPending = null;
                    this.gpsSearchingText = '';
                    if (this.accuracyCircle) {
                        this.map.removeLayer(this.accuracyCircle);
                        this.accuracyCircle = null;
                    }
                },

                addFullscreenControl() {
                    const icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16"><path fill="currentColor" d="M4 4h6V2H2v8h2V4zm14 0v6h2V2h-8v2h6zM4 20v-6H2v8h8v-2H4zm14 0h-6v2h8v-8h-2v6z"/></svg>';
                    this.makeControlButton('topleft', 'Layar penuh', icon, () => {
                        const container = this.map.getContainer();
                        if (document.fullscreenElement) {
                            document.exitFullscreen();
                        } else {
                            container.requestFullscreen?.();
                        }
                    });
                    document.addEventListener('fullscreenchange', () => setTimeout(() => this.map.invalidateSize(), 50));
                },
            };
        };

        window.dispatchEvent(new CustomEvent('leaflet-map-picker-script-loaded'));
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', defineLeafletMapPicker);
    } else {
        defineLeafletMapPicker();
    }
})();
