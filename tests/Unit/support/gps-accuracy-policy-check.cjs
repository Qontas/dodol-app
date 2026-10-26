// Harness Node — dijalankan oleh tests/Unit/GpsAccuracyPolicyTest.php via `node` (shell_exec).
// Memuat public/js/gps-accuracy.js SUNGGUHAN (bukan re-implementasi/mock logic-nya) ke dalam
// sandbox window/navigator palsu, lalu menjalankan fungsi aslinya dan melaporkan hasil sebagai
// JSON ke stdout. Ini mengunci KEBIJAKAN GPS brief 26 Okt 2026 (poin 3), bukan cuma pola teks
// source — kalau threshold band/opsi geolocation diubah diam-diam, test ini gagal walau teks
// sumbernya "terlihat" masih benar.
const path = require('path');
const vm = require('vm');
const fs = require('fs');

const filePath = path.join(__dirname, '..', '..', '..', 'public', 'js', 'gps-accuracy.js');
const source = fs.readFileSync(filePath, 'utf8');

function freshSandbox() {
    const watchCalls = [];
    const getCurrentPositionCalls = [];
    const clearedWatchIds = [];
    let nextWatchId = 1;
    let successCb = null;
    let errorCb = null;

    const navigatorMock = {
        geolocation: {
            watchPosition(success, error, options) {
                successCb = success;
                errorCb = error;
                const id = nextWatchId++;
                watchCalls.push({ id, options });
                return id;
            },
            getCurrentPosition(...args) {
                getCurrentPositionCalls.push(args);
            },
            clearWatch(id) {
                clearedWatchIds.push(id);
            },
        },
    };

    const windowMock = {};
    const sandbox = { window: windowMock, navigator: navigatorMock, setTimeout, clearTimeout, console };
    vm.createContext(sandbox);
    vm.runInContext(source, sandbox, { filename: 'gps-accuracy.js' });

    return {
        windowMock,
        emitPosition(lat, lng, accuracy) { successCb({ coords: { latitude: lat, longitude: lng, accuracy } }); },
        emitError(type) {
            // GeolocationPositionError mengekspos PERMISSION_DENIED/POSITION_UNAVAILABLE/TIMEOUT
            // sbg instance property (bukan cuma static) — tiru itu, krn source asli membandingkan
            // err.code === err.PERMISSION_DENIED (bukan string literal).
            const CODES = { PERMISSION_DENIED: 1, POSITION_UNAVAILABLE: 2, TIMEOUT: 3 };
            errorCb({ code: CODES[type], ...CODES });
        },
        watchCalls,
        getCurrentPositionCalls,
        clearedWatchIds,
    };
}

(async () => {
    const result = {};

    // --- 1. classifyGpsAccuracy band boundaries (brief: <=30 good, 31-100 confirm, 101-1000 bad, >1000 very_bad)
    {
        const { windowMock } = freshSandbox();
        const c = windowMock.classifyGpsAccuracy;
        result.classify = {
            '30': c(30), '30.1': c(30.1),
            '100': c(100), '100.1': c(100.1),
            '1000': c(1000), '1000.1': c(1000.1),
        };
    }

    // --- 2. gpsInaccurateMessage: >1000m wajib tambahan petunjuk izin "Perkiraan" Android, <=1000m tidak
    {
        const { windowMock } = freshSandbox();
        const m = windowMock.gpsInaccurateMessage;
        // Catatan: pesan DASAR (semua akurasi buruk) sudah menyebut kata "Perkiraan" sbg lawan
        // dari "Akurat" ("...bukan 'Perkiraan'..."), jadi regex /Perkiraan/ saja TIDAK cukup
        // spesifik. Yang membedakan >1000m adalah SUFFIX langkah Android ("Di Android: Setelan
        // -> ... -> 'Gunakan lokasi akurat'") — cek itu, bukan kata "Perkiraan" generik.
        result.messageHasAndroidHint500 = /Di Android:/.test(m(500));
        result.messageHasAndroidHint1500 = /Di Android:/.test(m(1500));
    }

    // --- 3. opsi geolocation: HANYA enableHighAccuracy:true + maximumAge:0, SATU watchPosition saja,
    //        TIDAK PERNAH getCurrentPosition (itu pola lama yg tak akurat).
    {
        const h = freshSandbox();
        h.windowMock.trackAccurateGps({ onDone: () => {} });
        result.watchOptions = h.watchCalls.map(c => c.options);
        result.usedGetCurrentPosition = h.getCurrentPositionCalls.length > 0;
        result.watchPositionCallCount = h.watchCalls.length;
    }

    // --- 4. "best accuracy" HARUS yg terkecil sejauh ini, BUKAN bacaan terakhir — bacaan yg lebih
    //        buruk setelah bacaan bagus tak boleh menimpa best. Dipaksa selesai lewat maxWaitMs
    //        kecil (bukan threshold akurasi), supaya bacaan terakhir yg sengaja dibuat LEBIH BURUK
    //        tak bisa "kebetulan" jadi best juga.
    {
        const h = freshSandbox();
        const donePromise = new Promise((resolve) => {
            h.windowMock.trackAccurateGps({ onDone: resolve }, { maxWaitMs: 60, stopAccuracyM: 1 });
        });
        h.emitPosition(1, 1, 50);   // best so far: 50
        h.emitPosition(2, 2, 500);  // lebih buruk — harus DIABAIKAN
        h.emitPosition(3, 3, 120);  // masih lebih buruk dari 50 — harus DIABAIKAN juga
        const best = await donePromise;
        result.bestAfterMixedReadings = best ? best.accuracy : null; // harus 50, bukan 120/500
    }

    // --- 5. berhenti otomatis begitu akurasi <= default STOP_ACCURACY_M (15m) — clearWatch dipanggil,
    //        onDone langsung dapat bacaan itu (tak nunggu maxWaitMs).
    {
        const h = freshSandbox();
        let done = null;
        h.windowMock.trackAccurateGps({ onDone: (best) => { done = best; } });
        h.emitPosition(1, 1, 15); // tepat di batas default
        result.stoppedAtDefaultThreshold = h.clearedWatchIds.length > 0;
        result.doneCalledOnEarlyStop = done !== null && done.accuracy === 15;
    }

    // --- 6. PERMISSION_DENIED langsung menyerah (onError), TANPA retry/fallback akurasi rendah apa pun.
    {
        const h = freshSandbox();
        let errorResult = null;
        h.windowMock.trackAccurateGps({ onError: (e) => { errorResult = e; } });
        h.emitError('PERMISSION_DENIED');
        result.permissionDeniedCalledOnError = errorResult !== null;
        result.permissionDeniedStopped = h.clearedWatchIds.length > 0;
        result.watchPositionCallCountAfterDenied = h.watchCalls.length; // harus tetap 1 — tak ada panggilan retry
    }

    // --- 7. error non-PERMISSION_DENIED (mis. POSITION_UNAVAILABLE sesaat) TIDAK boleh langsung
    //        menyerah — watchPosition harus tetap jalan sampai batas waktu/akurasi cukup (tak ada
    //        fallback instan ke bacaan buruk, tapi juga tak boleh berhenti prematur).
    {
        const h = freshSandbox();
        let errored = false;
        let done = null;
        h.windowMock.trackAccurateGps({ onError: () => { errored = true; }, onDone: (b) => { done = b; } });
        h.emitError('POSITION_UNAVAILABLE');
        result.transientErrorDidNotAbort = h.clearedWatchIds.length === 0 && !errored && done === null;
    }

    process.stdout.write(JSON.stringify(result));
    // Skenario 7 sengaja TIDAK menuntaskan tracker-nya (error sesaat tak boleh menghentikan
    // watch) — itu meninggalkan timer maxWaitMs default (20 detik) menggantung, yg kalau
    // dibiarkan bikin proses Node ini (dan tiap panggilan `node` dari PHPUnit, satu per metode
    // test) nunggu 20 detik penuh sebelum keluar. Sudah dapat semua hasil, keluar langsung.
    process.exit(0);
})();
