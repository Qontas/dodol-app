<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menanam cookie NON-httpOnly `auth_uid` = id user sesi LIVE pada tiap respons
 * ter-autentikasi. Dipakai guard pwa-token-refresh untuk membandingkan identitas
 * sesi-live (cookie) vs identitas yang TAMPIL (meta[auth-uid] pada snapshot
 * wire:navigate) secara SINKRON saat navigasi SPA/back-forward — tanpa menunggu
 * fetch, jadi snapshot tenant lain yang basi tak sempat berkedip muncul.
 *
 * Bukan kebocoran data: uid ini sudah ada di HTML (meta auth-uid). Dikecualikan
 * dari enkripsi cookie (lihat bootstrap/app.php) supaya terbaca oleh JS.
 */
class StampAuthUidCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (auth()->check()) {
            $response->headers->setCookie(
                cookie('auth_uid', (string) auth()->id(), 0, '/', null, null, false, false, 'lax')
            );
        }

        return $response;
    }
}
