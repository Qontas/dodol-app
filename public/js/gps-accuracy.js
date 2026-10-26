// Kebijakan GPS akurat — SATU sumber kebenaran, dipakai 3 tempat: peta operator
// (create-kiosk.blade.php), peta owner (leaflet-map-picker.js), dan "Urutkan Jarak"
// (active-trip.blade.php). Brief 26 Okt 2026 (fix GPS 23 Okt tidak pernah lolos uji
// lapangan):
//   - HANYA enableHighAccuracy:true, maximumAge:0. TIDAK ADA fallback akurasi rendah
//     (fallback lama — retry enableHighAccuracy:false — justru yang bikin lokasi meleset
//     ratusan meter diam-diam dianggap tepat).
//   - watchPosition maksimal 20 detik, simpan bacaan dgn accuracy TERKECIL, berhenti
//     otomatis begitu ≤15 m.
//   - Band hasil akhir: ≤30 m pakai otomatis, 31–100 m wajib konfirmasi, >100 m tolak
//     (>1000 m dapat petunjuk khusus izin "Perkiraan" Android).
// Bukan ES module (dimuat via <script src> biasa / @assets Livewire) — fungsi dipasang
// ke window spt file leaflet-map-picker.js yg sudah ada, biar konsisten.
(function () {
    var MAX_WAIT_MS = 20000;
    var STOP_ACCURACY_M = 15;

    // Lacak lokasi GPS akurasi-tinggi. callbacks: onUpdate(best) tiap bacaan membaik
    // (buat teks "Mencari… akurasi ±X m" yg hidup), onDone(best|null) begitu berhenti
    // (akurasi ≤15m ATAU 20 detik habis — best null kalau TAK PERNAH dapat satu bacaan
    // pun), onError({code}) utk izin ditolak / geolocation tak didukung.
    // opts.maxWaitMs / opts.stopAccuracyM: override utk test (hindari nunggu 20 detik asli).
    window.trackAccurateGps = function (callbacks, opts) {
        callbacks = callbacks || {};
        var maxWaitMs = (opts && opts.maxWaitMs) || MAX_WAIT_MS;
        var stopAccuracyM = (opts && opts.stopAccuracyM) || STOP_ACCURACY_M;

        if (!('geolocation' in navigator)) {
            if (callbacks.onError) callbacks.onError({ code: 'UNSUPPORTED' });
            return { stop: function () {} };
        }

        var best = null; // { lat, lng, accuracy }
        var watchId = null;
        var timeoutId = null;
        var done = false;

        function stop() {
            if (watchId !== null) {
                navigator.geolocation.clearWatch(watchId);
                watchId = null;
            }
            if (timeoutId !== null) {
                clearTimeout(timeoutId);
                timeoutId = null;
            }
        }

        function finish() {
            if (done) return;
            done = true;
            stop();
            if (callbacks.onDone) callbacks.onDone(best);
        }

        watchId = navigator.geolocation.watchPosition(
            function (pos) {
                var acc = pos.coords.accuracy;
                if (best === null || acc < best.accuracy) {
                    best = { lat: pos.coords.latitude, lng: pos.coords.longitude, accuracy: acc };
                    if (callbacks.onUpdate) callbacks.onUpdate(best);
                }
                if (acc <= stopAccuracyM) {
                    finish();
                }
            },
            function (err) {
                // Izin ditolak: percuma ditunggu/di-retry, langsung menyerah — TIDAK ada
                // fallback akurasi rendah (dihapus sesuai kebijakan).
                if (err && err.code === err.PERMISSION_DENIED) {
                    done = true;
                    stop();
                    if (callbacks.onError) callbacks.onError({ code: 'PERMISSION_DENIED', best: best });
                    return;
                }
                // Error lain (sinyal lemah/tak tersedia sesaat) — watchPosition tetap bisa
                // dapat fix berikutnya; biarkan jalan sampai batas waktu yg memutuskan.
            },
            { enableHighAccuracy: true, maximumAge: 0 }
        );

        timeoutId = setTimeout(finish, maxWaitMs);

        return { stop: stop };
    };

    // Klasifikasi band akurasi hasil AKHIR (dipanggil sekali setelah tracker berhenti,
    // BUKAN tiap update selama mencari).
    //   good    -> ≤30 m   : pasang pin otomatis
    //   confirm -> 31-100 m: pin dipasang tapi wajib konfirmasi
    //   bad     -> 101-1000m: jangan pasang pin
    //   very_bad-> >1000 m : jangan pasang pin + petunjuk izin "Perkiraan" Android
    window.classifyGpsAccuracy = function (accuracyMeters) {
        if (accuracyMeters <= 30) return 'good';
        if (accuracyMeters <= 100) return 'confirm';
        if (accuracyMeters <= 1000) return 'bad';
        return 'very_bad';
    };

    // Pesan "lokasi tidak akurat" (band bad/very_bad). >1000m dapat tambahan petunjuk
    // izin "Perkiraan" Android (Chrome: Setelan > Aplikasi > Chrome > Izin > Lokasi >
    // nyalakan "Gunakan lokasi akurat").
    window.gpsInaccurateMessage = function (accuracyMeters) {
        var acc = Math.round(accuracyMeters);
        var msg = 'Lokasi tidak akurat (±' + acc + ' m). Pastikan izin lokasi HP = "Akurat" ' +
            '(bukan "Perkiraan"), GPS nyala, coba di luar ruangan, atau geser pin manual.';
        if (accuracyMeters > 1000) {
            msg += ' Di Android: Setelan → Aplikasi → Chrome → Izin → Lokasi → ' +
                'nyalakan "Gunakan lokasi akurat".';
        }
        return msg;
    };
})();
