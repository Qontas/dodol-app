<?php

use App\Http\Controllers\OperatorDashboardController;
use App\Http\Controllers\OwnerDashboardController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::post('logout', function () {
    Auth::guard('web')->logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('login');
})->middleware('auth')->name('logout');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', function () {
        $role = auth()->user()->role;

        return redirect()->route("{$role}.dashboard");
    })->name('dashboard');
});

Route::middleware(['auth', 'verified', 'role:owner'])
    ->prefix('owner')
    ->name('owner.')
    ->group(function () {
        Route::get('/dashboard', [OwnerDashboardController::class, 'index'])
            ->name('dashboard');
    });

Route::middleware(['auth', 'verified', 'role:operator'])
    ->prefix('operator')
    ->name('operator.')
    ->group(function () {
        Route::get('/dashboard', [OperatorDashboardController::class, 'index'])
            ->name('dashboard');
    });

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

require __DIR__.'/auth.php';
