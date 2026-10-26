<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Mengunci KEBIJAKAN GPS AKURAT brief 26 Okt 2026 (poin 3) — SATU sumber kebenaran di
 * public/js/gps-accuracy.js, dipakai jg oleh create-kiosk.blade.php, active-trip.blade.php,
 * dan leaflet-map-picker.js. Fix GPS 23 Okt 2026 (retry enableHighAccuracy:false sbg fallback)
 * TIDAK PERNAH lolos uji lapangan karena fallback itu sendiri yg bikin lokasi meleset ratusan
 * meter diam-diam dianggap cukup baik — jadi test ini secara eksplisit melarang pola itu balik
 * lagi, bukan cuma mengecek fitur baru ada.
 *
 * Tidak ada Jest/Vitest di proyek ini (lihat package.json) — jadi test ini menjalankan
 * public/js/gps-accuracy.js yg SUNGGUHAN lewat Node (tests/Unit/support/gps-accuracy-policy-
 * check.cjs, di-vm.runInContext dgn window/navigator.geolocation palsu) dan membaca hasilnya
 * sbg JSON. Ini mengunci PERILAKU asli (band, opsi geolocation, tracking akurasi terkecil),
 * bukan cuma pola teks source yg gampang jebol kalau threshold diubah.
 */
class GpsAccuracyPolicyTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function runPolicyCheck(): array
    {
        $script = base_path('tests/Unit/support/gps-accuracy-policy-check.cjs');
        $this->assertFileExists($script);

        $output = shell_exec('node ' . escapeshellarg($script) . ' 2>&1');
        $this->assertNotNull($output, 'node gagal dijalankan utk gps-accuracy-policy-check.cjs');

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded,
            "Output node bukan JSON valid (kemungkinan error JS): \n$output");

        return $decoded;
    }

    public function test_classify_gps_accuracy_bands_match_brief_thresholds()
    {
        $r = $this->runPolicyCheck();
        $c = $r['classify'];

        $this->assertSame('good', $c['30'], '<=30m harus band good (otomatis pasang pin)');
        $this->assertSame('confirm', $c['30.1'], '>30m harus bukan lagi good');
        $this->assertSame('confirm', $c['100'], '<=100m harus band confirm (pin+wajib konfirmasi)');
        $this->assertSame('bad', $c['100.1'], '>100m harus band bad (JANGAN pasang pin)');
        $this->assertSame('bad', $c['1000'], '<=1000m masih band bad, bukan very_bad');
        $this->assertSame('very_bad', $c['1000.1'], '>1000m harus band very_bad (petunjuk izin Android)');
    }

    public function test_inaccurate_message_only_adds_android_permission_hint_above_1000m()
    {
        $r = $this->runPolicyCheck();

        $this->assertFalse($r['messageHasAndroidHint500'],
            'Petunjuk izin "Perkiraan" Android TIDAK boleh muncul di bawah >1000m');
        $this->assertTrue($r['messageHasAndroidHint1500'],
            'Petunjuk izin "Perkiraan" Android WAJIB muncul utk akurasi >1000m');
    }

    public function test_geolocation_uses_only_high_accuracy_with_no_fallback()
    {
        $r = $this->runPolicyCheck();

        $this->assertFalse($r['usedGetCurrentPosition'],
            'HANYA watchPosition yg boleh dipakai, bukan getCurrentPosition (pola lama tak akurat).');
        $this->assertSame(1, $r['watchPositionCallCount'],
            'Harus SATU panggilan watchPosition saja — lebih dari satu mengindikasikan retry/fallback.');

        $this->assertNotEmpty($r['watchOptions']);
        foreach ($r['watchOptions'] as $options) {
            $this->assertTrue($options['enableHighAccuracy'] ?? null,
                'enableHighAccuracy HARUS true — TIDAK ADA fallback akurasi rendah (itulah fix 23 Okt yg gagal uji lapangan).');
            $this->assertSame(0, $options['maximumAge'] ?? null,
                'maximumAge HARUS 0 — jangan pernah pakai cache lokasi lama.');
        }
    }

    public function test_best_accuracy_reading_is_kept_even_when_later_readings_are_worse()
    {
        $r = $this->runPolicyCheck();

        $this->assertSame(50, $r['bestAfterMixedReadings'],
            'Bacaan akurasi TERKECIL sejauh ini harus dipakai (50m), bukan bacaan terakhir '
            . '(500m/120m) — bacaan yg lebih buruk tak boleh menimpa best.');
    }

    public function test_tracker_stops_automatically_once_accuracy_reaches_default_15m()
    {
        $r = $this->runPolicyCheck();

        $this->assertTrue($r['stoppedAtDefaultThreshold'],
            'watchPosition harus dihentikan (clearWatch) begitu akurasi <= 15m default.');
        $this->assertTrue($r['doneCalledOnEarlyStop'],
            'onDone harus langsung dipanggil dgn bacaan itu, tak perlu nunggu batas waktu.');
    }

    public function test_permission_denied_aborts_immediately_without_retry()
    {
        $r = $this->runPolicyCheck();

        $this->assertTrue($r['permissionDeniedCalledOnError']);
        $this->assertTrue($r['permissionDeniedStopped'],
            'PERMISSION_DENIED harus langsung menghentikan watch, bukan terus mencoba.');
        $this->assertSame(1, $r['watchPositionCallCountAfterDenied'],
            'PERMISSION_DENIED TIDAK boleh memicu panggilan watchPosition kedua (retry/fallback).');
    }

    public function test_transient_error_does_not_abort_the_search()
    {
        $r = $this->runPolicyCheck();

        $this->assertTrue($r['transientErrorDidNotAbort'],
            'Error sesaat (mis. POSITION_UNAVAILABLE) TIDAK boleh langsung menyerah — '
            . 'watchPosition tetap jalan sampai batas waktu/akurasi cukup.');
    }

    /**
     * Jaring pengaman tambahan di level teks: kalau threshold di atas semua lolos tapi source
     * diam-diam menambahkan JALUR KEDUA (mis. pemanggilan getCurrentPosition dgn
     * enableHighAccuracy:false sbg fallback terpisah) yg tak pernah ditembus skenario test di
     * atas, pola teks ini tetap menangkapnya.
     */
    public function test_source_never_contains_low_accuracy_fallback_pattern()
    {
        $source = file_get_contents(public_path('js/gps-accuracy.js'));

        // Buang komentar // sebelum cek — file ini SENGAJA menyebut "enableHighAccuracy:false"
        // di dalam komentar prosa yg menjelaskan riwayat bug (fix lama yg dihapus), itu bukan
        // kode sungguhan dan harus tetap boleh ada sbg dokumentasi.
        $withoutLineComments = preg_replace('/\/\/.*$/m', '', $source);

        $this->assertStringNotContainsString('enableHighAccuracy: false', $withoutLineComments);
        $this->assertStringNotContainsString('enableHighAccuracy:false', $withoutLineComments);
        $this->assertStringNotContainsString('enableHighAccuracy : false', $withoutLineComments);
    }
}
