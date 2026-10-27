<?php

namespace App\Http\Middleware;

use App\Support\TripAutoCloser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * BRIEF 27 Okt 2026 "TRIP LUPA DIAKHIRI" item 2a/2b — pemicu tutup otomatis TANPA
 * cron (belum ada scheduler di Railway). Dipasang di grup route 'operator' (2a,
 * scoped ke trip operator itu sendiri) dan grup route 'owner' dashboard/Riwayat/
 * Laporan (2b, scoped ke trip owner itu sendiri) — lihat routes/web.php.
 *
 * super_admin SENGAJA dilewati: route Laporan/Riwayat juga dibuka super_admin
 * untuk melihat LINTAS owner (bukan satu owner session), jadi tidak ada satu
 * owner_id yang tepat untuk di-scope di sini.
 *
 * RINGAN (lihat TripAutoCloser::staleQuery()) — kasus normal (tidak ada trip
 * menggantung) HANYA menambah 1 query SELECT kosong per request. Lihat
 * TripAutoCloserTest::test_closing_stale_trips_adds_bounded_query_count().
 */
class AutoCloseStaleTrips
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            if ($user->role === 'operator') {
                TripAutoCloser::closeStaleForOperator($user->id);
            } elseif ($user->role === 'owner') {
                TripAutoCloser::closeStaleForOwner($user->id);
            }
        }

        return $next($request);
    }
}
