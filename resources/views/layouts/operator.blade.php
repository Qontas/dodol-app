<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Operator' }} &mdash; Cemilan Qontas</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased bg-slate-50 text-slate-900">
<div class="min-h-screen flex flex-col">
    <header class="bg-white border-b border-slate-200 px-4 py-3 flex items-center justify-between sticky top-0 z-10">
        <a href="{{ route('operator.dashboard') }}" class="text-base font-semibold text-amber-600">
            Cemilan Qontas
        </a>
        <div class="flex items-center gap-3">
            <span class="text-sm text-slate-600">{{ auth()->user()->name }}</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="text-xs text-red-600 hover:underline">Logout</button>
            </form>
        </div>
    </header>

    <main class="flex-1 p-4 pb-24">
        {{ $slot }}
    </main>

    {{-- Bottom navigation --}}
    <nav class="fixed bottom-0 inset-x-0 bg-white border-t border-slate-200 grid grid-cols-4 text-center text-xs z-20">
        @php
            $items = [
                ['label' => 'Beranda', 'route' => 'operator.dashboard', 'active' => request()->routeIs('operator.dashboard')],
                ['label' => 'Mulai Trip', 'route' => 'operator.trip.start', 'active' => request()->routeIs('operator.trip.*')],
                ['label' => 'Kios Baru', 'route' => 'operator.kiosks.create', 'active' => request()->routeIs('operator.kiosks.*')],
                ['label' => 'Profil', 'route' => 'profile', 'active' => request()->routeIs('profile')],
            ];
        @endphp
        @foreach ($items as $item)
            @if ($item['route'])
                <a href="{{ route($item['route']) }}"
                   class="min-h-[48px] flex items-center justify-center px-1 leading-tight {{ $item['active'] ? 'text-amber-600 font-semibold' : 'text-slate-600' }}">
                    {{ $item['label'] }}
                </a>
            @else
                <span class="min-h-[48px] flex items-center justify-center px-1 leading-tight text-slate-400 cursor-not-allowed" title="Segera hadir">{{ $item['label'] }}</span>
            @endif
        @endforeach
    </nav>
</div>
</body>
</html>
