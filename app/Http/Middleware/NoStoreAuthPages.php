<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pastikan halaman ter-AUTENTIKASI tidak pernah disimpan oleh bfcache browser,
 * proxy, maupun service worker. Lapisan pertahanan tambahan untuk bug multi-tenant
 * (akun B melihat dashboard akun A dari cache halaman). Hanya untuk halaman ter-auth,
 * JANGAN dipasang ke aset statis.
 */
class NoStoreAuthPages
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, no-cache, private, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
