<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Identitas perender halaman/snapshot — guard pwa-token-refresh memakai ini
         untuk deteksi snapshot wire:navigate lintas-tenant yang basi. WAJIB ada. --}}
    <meta name="auth-uid" content="{{ auth()->id() }}">
    <title>@yield('title', 'Akun') &mdash; Cemilan Qontas</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.pwa-head')
    @include('partials.pwa-token-refresh')
    @livewireStyles
</head>
<body class="font-sans antialiased bg-slate-100 text-slate-900">
@php
    // Label peran aman untuk SEMUA role (super admin & lainnya). Tidak ada
    // link ke resource owner.* di sini → super admin tak akan kena 403.
    $roleLabel = auth()->user()->isSuperAdmin()
        ? 'Super Admin'
        : ucfirst((string) auth()->user()->role);
@endphp
<div class="min-h-screen flex flex-col">
    {{-- Top bar brand minimal: brand + kembali ke panel sesuai role + logout. --}}
    <header class="bg-slate-900 text-slate-100">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 py-3 flex items-center justify-between gap-4">
            <a href="{{ auth()->user()->homePath() }}" class="flex flex-col leading-tight">
                <span class="text-lg font-semibold text-amber-400">Cemilan Qontas</span>
                <span class="text-xs text-slate-400">{{ $roleLabel }}</span>
            </a>
            <div class="flex items-center gap-4 text-sm">
                {{-- homePath() role-aware: super_admin → /admin, lainnya → {role}.dashboard. Aman, tak ada 403. --}}
                <a href="{{ auth()->user()->homePath() }}" class="text-slate-300 hover:text-white">&larr; Kembali ke Panel</a>
                <span class="hidden sm:inline text-slate-600">|</span>
                <span class="font-medium hidden sm:inline">{{ auth()->user()->name }}</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="text-red-400 hover:text-red-300 font-medium">Logout</button>
                </form>
            </div>
        </div>
    </header>

    <main class="flex-1 py-8 px-4 sm:px-6">
        @yield('content')
    </main>
</div>
@livewireScripts
</body>
</html>
