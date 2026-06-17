<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Owner') &mdash; Cemilan Qontas</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.pwa-head')
    @include('partials.pwa-bfcache-guard')
    {{-- Alpine datang dari Livewire. Halaman owner non-Livewire (laporan,
         settings) tidak meng-auto-inject script Livewire, jadi muat manual di
         sini agar Alpine selalu ada (dropdown akun & sidebar perlu Alpine). --}}
    @livewireStyles
</head>
<body class="font-sans antialiased bg-slate-100 text-slate-900">
<div x-data="{ sidebarOpen: false }" class="min-h-screen flex">
    {{-- Sidebar --}}
    <aside
        class="fixed inset-y-0 left-0 z-30 w-64 bg-slate-900 text-slate-100 transform transition-transform duration-200 ease-in-out lg:translate-x-0 lg:static lg:inset-auto"
        :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
    >
        <div class="px-6 py-5 border-b border-slate-700">
            <a href="{{ route('owner.dashboard') }}" class="text-lg font-semibold text-amber-400">
                Cemilan Qontas
            </a>
            <p class="mt-1 text-xs text-slate-400">Panel Owner</p>
        </div>
        <nav class="px-4 py-4 space-y-1 text-sm">
            @php
                $nav = [
                    ['label' => 'Dashboard', 'route' => 'owner.dashboard', 'active' => request()->routeIs('owner.dashboard')],
                    ['label' => 'Manajemen Kios', 'route' => 'filament.owner.resources.kiosks.index', 'active' => request()->routeIs('filament.owner.resources.kiosks.*')],
                    ['label' => 'Manajemen Area', 'route' => 'filament.owner.resources.clusters.index', 'active' => request()->routeIs('filament.owner.resources.clusters.*')],
                    ['label' => 'Manajemen Supplier', 'route' => 'filament.owner.resources.suppliers.index', 'active' => request()->routeIs('filament.owner.resources.suppliers.*')],
                    ['label' => 'Manajemen Anggota', 'route' => 'filament.owner.resources.operators.index', 'active' => request()->routeIs('filament.owner.resources.operators.*')],
                    ['label' => 'Stok Masuk', 'route' => 'filament.owner.resources.procurement-batches.index', 'active' => request()->routeIs('filament.owner.resources.procurement-batches.*')],
                    ['label' => 'Laporan & Analitik', 'route' => 'owner.reports.monthly', 'active' => request()->routeIs('owner.reports.*')],
                    ['label' => 'Settings', 'route' => 'owner.settings', 'active' => request()->routeIs('owner.settings')],
                ];
            @endphp
            @foreach ($nav as $item)
                @if ($item['route'])
                    <a href="{{ route($item['route']) }}"
                       class="block px-3 py-2 rounded-md {{ $item['active'] ? 'bg-amber-600 text-white' : 'text-slate-300 hover:bg-slate-800' }}">
                        {{ $item['label'] }}
                    </a>
                @else
                    <span class="block px-3 py-2 rounded-md text-slate-500 cursor-not-allowed" title="Segera hadir">
                        {{ $item['label'] }}
                    </span>
                @endif
            @endforeach
        </nav>
    </aside>

    {{-- Backdrop mobile --}}
    <div x-show="sidebarOpen" x-cloak
         @click="sidebarOpen = false"
         class="fixed inset-0 z-20 bg-black/40 lg:hidden"></div>

    {{-- Main column --}}
    <div class="flex-1 flex flex-col min-w-0">
        <header class="bg-white border-b border-slate-200 px-4 sm:px-6 py-3 flex items-center justify-between">
            <button @click="sidebarOpen = !sidebarOpen" class="lg:hidden p-2 -ml-2 text-slate-600">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>
            <div class="hidden lg:block text-sm font-medium text-slate-700">@yield('title', 'Dashboard')</div>
            <div x-data="{ open: false }" class="relative">
                <button @click="open = !open" class="flex items-center gap-2 text-sm text-slate-700 hover:text-slate-900">
                    <span class="font-medium">{{ auth()->user()->name }}</span>
                    <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                </button>
                <div x-show="open" x-cloak @click.outside="open = false"
                     class="absolute right-0 mt-2 w-44 bg-white rounded-md shadow-lg border border-slate-200 py-1 z-10">
                    <a href="{{ route('profile') }}" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-100">Profil</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-slate-100">Logout</button>
                    </form>
                </div>
            </div>
        </header>
        <main class="flex-1 p-4 sm:p-6">
            @yield('content')
        </main>
    </div>
</div>
@livewireScripts
</body>
</html>
