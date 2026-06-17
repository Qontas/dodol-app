<?php

use App\Http\Controllers\Owner\MonthlyReportController;
use App\Http\Controllers\Owner\TripDeleteController;
use App\Http\Controllers\Owner\TripExportController;
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
        Route::get('/trips/{trip}/export/pdf', [TripExportController::class, 'pdf'])
            ->name('trips.export.pdf');
        Route::get('/trips/{trip}/export/excel', [TripExportController::class, 'excel'])
            ->name('trips.export.excel');

        Route::delete('/trips/{trip}', [TripDeleteController::class, 'destroy'])
            ->name('trips.destroy');

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

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

require __DIR__.'/auth.php';
