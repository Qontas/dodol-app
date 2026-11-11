<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * BRIEF 11 Nov 2026 "PERFORMA MELAMBAT" item 1 — pencatat request lambat + Server-Timing.
 *
 * Tanpa akses ke produksi, satu-satunya cara tahu halaman mana yang lambat DI SANA adalah
 * app sendiri yang mencatat. Middleware ini:
 *
 *  1. Mengukur durasi request + jumlah & total waktu query.
 *  2. Kalau durasi > config('performance.slow_ms') ATAU query > config('performance.slow_queries'):
 *     menulis SATU baris log level warning, berawalan `SLOW_REQUEST`, berisi HANYA
 *     route name, method, status, durasi, jumlah query, total ms query, dan role user.
 *     TIDAK ada email/nama/IP, TIDAK ada URL mentah (id kios/trip), TIDAK ada isi query
 *     atau parameter — route name dipakai sebagai pengganti URL, dan kalau route tak
 *     bernama dipakai TEMPLATE URI-nya (`operator/trip/{tripId}`), bukan URL aslinya.
 *  3. Menambahkan header `Server-Timing: app;dur=…, db;dur=…, q;desc="N queries"`
 *     pada respons HTML (terlihat di DevTools). Hanya angka.
 *
 * Dipasang GLOBAL dan paling depan (bootstrap/app.php) supaya durasinya mencakup
 * sesi, auth, dan middleware lain. Didaftarkan sebagai singleton (AppServiceProvider):
 * penghitung di bawah adalah state instance yang di-reset tiap request, dan listener
 * query didaftarkan SEKALI per instance — penting di Octane/FrankenPHP (worker hidup
 * lintas request; listener per-request akan menumpuk dan menghitung ganda).
 *
 * Catatan cakupan: yang terukur adalah waktu di dalam aplikasi (sampai respons selesai
 * dibuat). Waktu boot PHP/worker sebelum middleware ini dan waktu transfer jaringan
 * ke HP TIDAK terukur — itu sebabnya angka ini adalah batas bawah waktu yang dirasakan.
 */
class LogSlowRequests
{
    private bool $listening = false;

    private int $queryCount = 0;

    private float $queryMs = 0.0;

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('performance.enabled')) {
            return $next($request);
        }

        $this->startCounting();
        $startedAt = hrtime(true);

        $response = $next($request);

        $durationMs = (hrtime(true) - $startedAt) / 1e6;
        $queryCount = $this->queryCount;
        $queryMs = $this->queryMs;

        if (config('performance.server_timing') && $this->isHtml($response)) {
            $response->headers->set('Server-Timing', sprintf(
                'app;dur=%.1f, db;dur=%.1f, q;desc="%d queries"',
                $durationMs,
                $queryMs,
                $queryCount,
            ));
        }

        if ($durationMs > (int) config('performance.slow_ms') || $queryCount > (int) config('performance.slow_queries')) {
            $this->log($request, $response, $durationMs, $queryCount, $queryMs);
        }

        return $response;
    }

    private function startCounting(): void
    {
        $this->queryCount = 0;
        $this->queryMs = 0.0;

        if ($this->listening) {
            return;
        }

        $this->listening = true;

        DB::listen(function (QueryExecuted $query): void {
            $this->queryCount++;
            $this->queryMs += $query->time;
        });
    }

    private function isHtml(Response $response): bool
    {
        return str_contains((string) $response->headers->get('Content-Type'), 'text/html');
    }

    private function log(Request $request, Response $response, float $durationMs, int $queryCount, float $queryMs): void
    {
        // Dibaca SETELAH angka query diambil, supaya query pencarian user (kalau sesi
        // belum sempat memuatnya) tidak ikut menaikkan hitungan yang dilaporkan.
        $role = $request->user()?->role ?? 'guest';

        $route = $request->route();
        $routeName = $route?->getName() ?? ($route ? 'uri:'.$route->uri() : 'unmatched');

        Log::channel((string) config('performance.log_channel'))->warning(sprintf(
            'SLOW_REQUEST route=%s method=%s status=%d dur_ms=%d queries=%d db_ms=%d role=%s',
            $routeName,
            $request->method(),
            $response->getStatusCode(),
            round($durationMs),
            $queryCount,
            round($queryMs),
            $role,
        ));
    }
}
