// Leaflet map picker hand-rolled utk form OWNER (KioskResource) — pengganti
// dotswan/filament-map-picker. Peta operator (create-kiosk.blade.php) sudah
// pakai pola sama (L.map + setView langsung) dan terbukti stabil; dotswan
// sempat 3x jadi sumber bug (grey-tile invalidateSize, lalu refreshMap tak
// pernah kepanggil krn IntersectionObserver-nya sendiri). Di sini TIDAK ada
// observer pembongkar peta — map dibuat sekali, "Loncat ke lokasi" langsung
// panggil map.setView(), tanpa perantara.
document.addEventListener('DOMContentLoaded', () => {
    window.leafletMapPicker = function ($wire, config) {
        return {
            map: null,
            marker: null,

            // State tombol prominent "Pakai Lokasi Saya (GPS)" (dirender di blade,
            // reaktif Alpine). showGpsButton ikut config yg sama dgn kontrol peta kecil.
            showGpsButton: !!config.showMyLocationButton,
            gpsLoading: false,
            gpsError: '',
            // Akurasi bacaan GPS terbaik (meter) utk badge "Akurasi ±X meter" + peringatan
            // kuning (>50m) di blade. null = belum ada bacaan GPS (lokasi default/manual).
            gpsAccuracy: null,
            accuracyCircle: null,
            gpsWatchId: null,
            gpsTimeoutId: null,
            gpsGotAnyFix: false,

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

                // maxNativeZoom = maxZoom: cegah over-zoom ke level tanpa tile
                // (akar "grey saat zoom kuat", sama fix dgn peta operator). Tanpa
                // `subdomains`: tilesUrl standar (tile.openstreetmap.org) host tunggal,
                // tak pakai placeholder {s} (beda dari CARTO yang dibuang 20 Okt 2026
                // krn skrg wajib API key di zoom dekat — lihat KioskResource).
                L.tileLayer(config.tilesUrl, {
                    attribution: config.attribution,
                    maxZoom: config.maxZoom,
                    maxNativeZoom: config.maxZoom,
                }).addTo(this.map);

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

            // Sama pola dgn peta operator (create-kiosk.blade.php, fix 21 Okt 2026): versi
            // getCurrentPosition SEKALI sebelumnya menerima bacaan apapun yg datang duluan —
            // bisa fix jaringan/wifi yang meleset ratusan meter, diam-diam dianggap tepat.
            // watchPosition maks 15 detik, simpan & pakai bacaan dgn accuracy TERKECIL,
            // berhenti lebih awal kalau ≤20m. Kalau sampai habis waktu tak pernah dapat
            // ≤20m, bacaan terbaik yg ada tetap dipakai TAPI gpsAccuracy + lingkaran peta
            // menandainya jelas sbg perkiraan kasar (blade menampilkan peringatan >50m).
            useMyLocation() {
                this.gpsError = '';

                if (!('geolocation' in navigator)) {
                    this.gpsError = 'Browser ini tidak mendukung GPS/lokasi.';
                    return;
                }

                this.stopGpsWatch();
                this.gpsGotAnyFix = false;
                this.gpsLoading = true;

                this.gpsWatchId = navigator.geolocation.watchPosition(
                    (pos) => {
                        const acc = pos.coords.accuracy;
                        if (this.gpsAccuracy === null || acc < this.gpsAccuracy) {
                            this.gpsGotAnyFix = true;
                            const { latitude, longitude } = pos.coords;
                            this.map.setView([latitude, longitude], 17);
                            this.marker.setLatLng([latitude, longitude]);
                            this.writeCoordinates(latitude, longitude);
                            this.updateGpsAccuracyUi(latitude, longitude, acc);
                            // Cegah tile abu-abu setelah recenter (container mungkin baru
                            // di-relayout) — sama fix dgn ResizeObserver invalidateSize.
                            setTimeout(() => this.map.invalidateSize(), 60);
                        }
                        if (acc <= 20) {
                            this.finishGps();
                        }
                    },
                    (err) => {
                        // Izin ditolak: percuma ditunggu/di-retry, langsung menyerah.
                        if (err && err.code === err.PERMISSION_DENIED) {
                            this.stopGpsWatch();
                            this.gpsLoading = false;
                            this.gpsError = 'Izin lokasi ditolak. Aktifkan izin lokasi untuk situs ini di browser, lalu coba lagi.';
                            return;
                        }
                        // Error lain (sinyal lemah/tak tersedia sesaat) — watchPosition masih
                        // bisa dapat fix berikutnya; biarkan jalan sampai batas waktu 15 detik
                        // di bawah yang memutuskan, jangan menyerah di error pertama.
                    },
                    { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
                );

                this.gpsTimeoutId = setTimeout(() => this.finishGps(), 15000);
            },

            // Dipanggil begitu akurasi ≤20m ATAU batas waktu 15 detik habis (mana dulu).
            finishGps() {
                this.stopGpsWatch();
                this.gpsLoading = false;
                if (!this.gpsGotAnyFix) {
                    this.gpsError = 'Terlalu lama mencari lokasi. Sinyal GPS tidak didapat dalam 15 detik, coba lagi di tempat yang lebih terbuka.';
                }
            },

            stopGpsWatch() {
                if (this.gpsWatchId !== null) {
                    navigator.geolocation.clearWatch(this.gpsWatchId);
                    this.gpsWatchId = null;
                }
                if (this.gpsTimeoutId !== null) {
                    clearTimeout(this.gpsTimeoutId);
                    this.gpsTimeoutId = null;
                }
            },

            // Badge "Akurasi ±X meter" (blade) + lingkaran radius akurasi di peta (L.circle).
            // >50m dianggap kurang akurat -> blade menampilkan peringatan kuning eksplisit
            // (jangan diam-diam dianggap tepat).
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
});
