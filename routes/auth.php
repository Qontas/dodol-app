<?php

use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::middleware('guest')->group(function () {
    // ⛔ SELF-REGISTER DITUTUP (keamanan). Sistem ini multi-tenant TERTUTUP: akun HANYA
    // dibuat oleh owner (untuk operator) atau super admin (untuk owner) lewat panel.
    // Dulu /register PUBLIK + kolom role DEFAULT 'owner' → siapa pun di internet bisa
    // mendaftar & langsung jadi OWNER penuh. Route register (GET & POST) DIHAPUS total;
    // view-nya juga dihapus. Pertahanan berlapis: default kolom role kini 'operator'
    // (migration), jadi jalur pembuatan user yang lupa set role tak pernah jadi owner.
    Volt::route('login', 'pages.auth.login')
        ->name('login');

    Volt::route('forgot-password', 'pages.auth.forgot-password')
        ->name('password.request');

    Volt::route('reset-password/{token}', 'pages.auth.reset-password')
        ->name('password.reset');
});

Route::middleware('auth')->group(function () {
    Volt::route('verify-email', 'pages.auth.verify-email')
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Volt::route('confirm-password', 'pages.auth.confirm-password')
        ->name('password.confirm');
});
