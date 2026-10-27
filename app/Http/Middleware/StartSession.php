<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession as BaseStartSession;

/**
 * BRIEF 27 Okt 2026 "KEMBALI MENAMPILKAN sw.js" — link "← Kembali ke Riwayat
 * Trip" (owner/trips/show) dulu pakai url()->previous(), yang (kalau header
 * Referer kosong — PWA dibuka ulang dari home-screen/app-switcher) FALLBACK ke
 * session('_previous.url'). Akar masalahnya BUKAN /sw.js sungguhan (file statis,
 * dilayani server TANPA lewat Laravel sama sekali — lihat public/sw.js +
 * vendor/.../resources/server.php & Caddyfile produksi), tapi StartSession
 * bawaan Laravel: storeCurrentUrl() menimpa _previous.url untuk SEMUA request
 * GET yang cocok route & bukan ajax/prefetch/precognitive — termasuk endpoint
 * NON-HALAMAN seperti /csrf-token (dipoll pwa-token-refresh di setiap
 * pageshow/visibilitychange app) dan /kiosks/{id}/photo (dimuat <img> di semua
 * daftar kios). Keduanya route GET sungguhan yang LOLOS kondisi bawaan, jadi
 * diam-diam MENJADI tujuan "kembali" berikutnya kalau Referer kosong.
 *
 * Subclass ini mengecualikan path non-halaman dari storeCurrentUrl() —
 * halaman sungguhan (dashboard, Riwayat Trip, detail trip, dst) tetap
 * menimpa _previous.url seperti biasa. /sw.js, /manifest.webmanifest, /build/*
 * didaftarkan juga sebagai jaring pengaman (dokumentasi niat) walau saat ini
 * tidak pernah mencapai middleware ini (statis, bukan route Laravel).
 *
 * Didaftarkan lewat container binding (lihat AppServiceProvider::register()),
 * BUKAN mengedit vendor — Pipeline route resolve middleware via
 * app()->make(StartSession::class), jadi binding abstrak→konkret ini otomatis
 * dipakai di seluruh middleware group 'web'.
 */
class StartSession extends BaseStartSession
{
    /**
     * @var array<int, string>
     */
    private const EXCLUDED_PATTERNS = [
        'csrf-token',
        'kiosks/*/photo',
        'sw.js',
        'manifest.webmanifest',
        'build/*',
    ];

    protected function storeCurrentUrl(Request $request, $session)
    {
        if ($request->is(self::EXCLUDED_PATTERNS)) {
            return;
        }

        parent::storeCurrentUrl($request, $session);
    }
}
