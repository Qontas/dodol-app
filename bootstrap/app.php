<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\TokenMismatchException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
            'no-store' => \App\Http\Middleware\NoStoreAuthPages::class,
        ]);

        // Cap identitas sesi-live ke cookie readable (multi-tenant guard sinkron).
        $middleware->web(append: [
            \App\Http\Middleware\StampAuthUidCookie::class,
        ]);

        // auth_uid harus terbaca JS → jangan dienkripsi (isinya cuma id, sudah
        // ada di meta[auth-uid] HTML, bukan data sensitif).
        $middleware->encryptCookies(except: ['auth_uid']);

        // #2 — Logout kebal CSRF-failure: kegagalan CSRF pada logout itu jinak
        // (paling buruk = ter-logout). Operator lapangan dgn token basi (PWA dibuka
        // setelah remember-me merotasi sesi) HARUS tetap bisa logout, bukan kena 419.
        // Rute logout TETAP butuh auth + POST — hanya verifikasi CSRF yang dilewati.
        $middleware->validateCsrfTokens(except: ['logout']);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // #3 — Handler 419 ramah untuk form NON-logout (visit/settlement/settings).
        // Operator tidak pernah lihat error mentah.
        $exceptions->render(function (TokenMismatchException $e, $request) {
            // Request Livewire: biarkan status 419 lewat → frontend Livewire punya
            // penanganan page-expired sendiri (token sudah di-refresh saat resume via
            // partial pwa-token-refresh, jadi ini sangat jarang terjadi).
            if ($request->hasHeader('X-Livewire')) {
                return null;
            }

            // ⚠️ SENGAJA tidak auto re-submit form lama → cegah transaksi dobel
            // (settlement/visit keitung 2x = kerusakan keuangan). Arahkan ke form segar.
            if (auth()->check()) {
                return redirect(auth()->user()->homePath())
                    ->with('status', 'Sesi diperbarui. Silakan ulangi aksi terakhir.');
            }

            return redirect()->route('login')
                ->with('status', 'Sesi berakhir. Silakan masuk lagi.');
        });
    })->create();
