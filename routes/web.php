<?php

use App\Http\Controllers\KioskPhotoController;
use App\Http\Controllers\Owner\MonthlyReportController;
use App\Http\Controllers\Owner\TripDeleteController;
use App\Http\Controllers\Owner\TripExportController;
use App\Http\Controllers\Owner\TripHistoryController;
use App\Http\Controllers\OwnerDashboardController;
use App\Http\Controllers\OwnerSettingsController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Root auth-aware: sudah login → dashboard sesuai role; belum → landing (marketing).
Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }

    return view('landing');
})->name('home');

Route::post('logout', function () {
    Auth::guard('web')->logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('login');
})->middleware('auth')->name('logout');

// Token CSRF sesi terkini + id user — dipakai pwa-token-refresh saat app resume
// agar token form (termasuk logout) selalu sinkron (akar fix 419) + deteksi
// pergantian identitas (multi-tenant). Auth wajib; tak boleh di-cache SW.
Route::get('csrf-token', function () {
    return response()->json([
        'token' => csrf_token(),
        'uid' => auth()->id(),
        // homePath user sesi-terkini → guard identitas bisa hard-nav ke dashboard
        // milik user yang BENAR saat snapshot wire:navigate basi terdeteksi.
        'home' => auth()->user()?->homePath(),
    ])->header('Cache-Control', 'no-store, no-cache, private, must-revalidate');
})->middleware('auth')->name('csrf-token');

// Proxy same-origin foto kios (R2 -> app -> browser). SENGAJA TANPA 'no-store':
// endpoint ini butuh Cache-Control publik (KioskPhotoController) supaya browser
// & SW bisa cache-first — 'no-store' akan menimpa itu jadi tak pernah ke-cache.
// Tanpa batasan role: owner/operator/super_admin sama-sama boleh lihat foto
// kios; isolasi tenant sudah otomatis lewat OwnerScope pada route model binding
// Kiosk (owner lain -> ModelNotFoundException -> 404).
Route::middleware(['auth'])
    ->get('/kiosks/{kiosk}/photo', [KioskPhotoController::class, 'show'])
    ->name('kiosks.photo');

Route::middleware(['auth', 'verified', 'no-store'])->group(function () {
    Route::get('/dashboard', function () {
        // Role-aware: super_admin→/admin, owner/operator→{role}.dashboard.
        return redirect(auth()->user()->homePath());
    })->name('dashboard');
});

Route::middleware(['auth', 'verified', 'no-store', 'role:owner'])
    ->prefix('owner')
    ->name('owner.')
    ->group(function () {
        Route::get('/dashboard', [OwnerDashboardController::class, 'index'])
            ->name('dashboard');

        Route::post('/kiosks/{kiosk}/reactivate', [OwnerDashboardController::class, 'reactivateKiosk'])
            ->name('kiosks.reactivate');

        Route::get('/settings', [OwnerSettingsController::class, 'index'])
            ->name('settings');
        Route::put('/settings', [OwnerSettingsController::class, 'update'])
            ->name('settings.update');
    });

// Laporan & export — owner + super admin (otorisasi per-trip di controller).
Route::middleware(['auth', 'verified', 'no-store', 'role:owner,super_admin'])
    ->prefix('owner')
    ->name('owner.')
    ->group(function () {
        // Riwayat Trip (daftar semua trip selesai + filter + detail + kelola arsip).
        Route::get('/trips', [TripHistoryController::class, 'index'])
            ->name('trips.index');

        Route::get('/trips/{trip}/export/pdf', [TripExportController::class, 'pdf'])
            ->name('trips.export.pdf');
        Route::get('/trips/{trip}/export/excel', [TripExportController::class, 'excel'])
            ->name('trips.export.excel');

        // Detail & pulihkan pakai withTrashed → trip terarsip pun bisa ter-bind.
        Route::get('/trips/{trip}', [TripHistoryController::class, 'show'])
            ->name('trips.show')
            ->withTrashed();

        Route::delete('/trips/{trip}', [TripDeleteController::class, 'destroy'])
            ->name('trips.destroy');

        Route::post('/trips/{trip}/restore', [TripDeleteController::class, 'restore'])
            ->name('trips.restore')
            ->withTrashed();

        Route::get('/reports/monthly', [MonthlyReportController::class, 'index'])
            ->name('reports.monthly');
        Route::get('/reports/monthly/export/pdf', [MonthlyReportController::class, 'pdf'])
            ->name('reports.monthly.pdf');
        Route::get('/reports/monthly/export/excel', [MonthlyReportController::class, 'excel'])
            ->name('reports.monthly.excel');
    });

Route::middleware(['auth', 'verified', 'no-store', 'role:operator'])
    ->prefix('operator')
    ->name('operator.')
    ->group(function () {
        Route::get('/dashboard', \App\Livewire\Operator\Dashboard::class)->name('dashboard');
        Route::get('/trip/start', \App\Livewire\Operator\StartTrip::class)->name('trip.start');
        Route::get('/trip/{tripId}', \App\Livewire\Operator\ActiveTrip::class)->name('trip.active');
        Route::get('/kiosks/create', \App\Livewire\Operator\CreateKiosk::class)->name('kiosks.create');
        Route::get('/profile', \App\Livewire\Operator\Profile::class)->name('profile');
    });

// no-store eksplisit (sabuk pengaman): Livewire sudah set no-store untuk halaman
// berkomponen, ini menegaskan niat + jaga kalau struktur view berubah.
Route::view('profile', 'profile')
    ->middleware(['auth', 'no-store'])
    ->name('profile');

require __DIR__.'/auth.php';
