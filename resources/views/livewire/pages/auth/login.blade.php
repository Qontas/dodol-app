<?php

use App\Livewire\Forms\LoginForm;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.auth')] class extends Component
{
    public LoginForm $form;

    /**
     * Handle an incoming authentication request.
     */
    public function login(): void
    {
        $this->validate();

        $this->form->authenticate();

        Session::regenerate();

        // Full reload (TANPA navigate:true) di batas login → flush snapshot
        // wire:navigate/bfcache, cegah dashboard akun sebelumnya muncul (multi-tenant).
        $this->redirectIntended(default: route('dashboard', absolute: false));
    }
}; ?>

{{-- SATU root element (div) — WAJIB untuk komponen Livewire full-page. Dokumen HTML
     (doctype/head/body) ada di layouts.auth. Dulu view ini me-render seluruh dokumen
     sendiri (root = <html>) di bawah layouts.blank → saat login GAGAL, morph error
     validasi Livewire mengosongkan <body> ("Could not find Livewire component in DOM
     tree") = HALAMAN PUTIH tanpa pesan. Kini error tampil inline, tak pernah blank. --}}
<div class="w-full max-w-md px-6">
    <div class="text-center mb-8">
        <h1 class="text-3xl font-extrabold text-amber-600 tracking-tight">Cemilan Qontas</h1>
        <p class="text-slate-500 mt-2 font-medium">Sistem Distribusi Dodol</p>
    </div>

    <div class="bg-white rounded-2xl shadow-xl border border-slate-100 overflow-hidden">
        <div class="px-8 py-8">
            <x-auth-session-status class="mb-4" :status="session('status')" />

            <form wire:submit="login">
                <div>
                    <label for="email" class="block text-sm font-semibold text-slate-700">Email</label>
                    <input id="email" wire:model="form.email" class="block mt-2 w-full rounded-lg border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 sm:text-sm" type="email" name="email" required autofocus autocomplete="username" placeholder="Masukkan email anda..." />
                    <x-input-error :messages="$errors->get('form.email')" class="mt-2" />
                </div>

                <div class="mt-6">
                    <label for="password" class="block text-sm font-semibold text-slate-700">Password</label>
                    <input id="password" wire:model="form.password" class="block mt-2 w-full rounded-lg border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 sm:text-sm" type="password" name="password" required autocomplete="current-password" placeholder="••••••••" />
                    <x-input-error :messages="$errors->get('form.password')" class="mt-2" />
                </div>

                <div class="flex items-center justify-between mt-6">
                    <label for="remember_me" class="inline-flex items-center cursor-pointer">
                        <input id="remember_me" wire:model="form.remember" type="checkbox" class="rounded border-slate-300 text-amber-600 shadow-sm focus:ring-amber-500" name="remember">
                        <span class="ms-2 text-sm text-slate-600">Ingat Saya</span>
                    </label>
                </div>

                <div class="mt-8">
                    <button type="submit" class="w-full flex justify-center py-3 px-4 border border-transparent rounded-lg shadow-sm text-sm font-bold text-white bg-amber-600 hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-amber-500 transition-all duration-200">
                        Masuk ke Panel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="text-center mt-8 text-xs text-slate-400 font-medium">
        &copy; {{ date('Y') }} Cemilan Qontas Distribusi.
    </div>
</div>
