@extends('layouts.owner')
@section('title', 'Dashboard Owner')

@section('content')
    <div class="max-w-6xl mx-auto space-y-6">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">Dashboard Owner</h1>
            <p class="mt-1 text-slate-600">Selamat datang, {{ $user->name }}.</p>
        </div>

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white rounded-lg border border-slate-200 p-4">
                <div class="text-xs uppercase tracking-wide text-slate-500">Total Kios</div>
                <div class="mt-2 text-3xl font-bold text-slate-900">{{ $stats['total_kios'] }}</div>
            </div>
            <div class="bg-white rounded-lg border border-slate-200 p-4">
                <div class="text-xs uppercase tracking-wide text-slate-500">Total Cluster</div>
                <div class="mt-2 text-3xl font-bold text-slate-900">{{ $stats['total_cluster'] }}</div>
            </div>
            <div class="bg-white rounded-lg border border-slate-200 p-4">
                <div class="text-xs uppercase tracking-wide text-slate-500">Total Supplier</div>
                <div class="mt-2 text-3xl font-bold text-slate-900">{{ $stats['total_supplier'] }}</div>
            </div>
            <div class="bg-white rounded-lg border border-slate-200 p-4">
                <div class="text-xs uppercase tracking-wide text-slate-500">Total Operator</div>
                <div class="mt-2 text-3xl font-bold text-slate-900">{{ $stats['total_operator'] }}</div>
            </div>
        </div>

        @if (array_sum($stats) === 0)
            <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-lg p-4 text-sm">
                Database masih kosong. Mulai dengan input cluster &amp; kios dari menu sidebar.
            </div>
        @endif

        {{-- Widget statistik operasional --}}
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            {{-- Omset Hari Ini --}}
            <div class="bg-white rounded-lg border border-slate-200 p-5">
                <div class="flex items-center gap-2 text-slate-500">
                    <svg class="h-5 w-5 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span class="text-xs uppercase tracking-wide font-medium">Omset Hari Ini</span>
                </div>
                <div class="mt-3 text-2xl font-bold text-green-600">
                    Rp {{ number_format($omsetHariIni, 0, ',', '.') }}
                </div>
            </div>

            {{-- Kios Overdue --}}
            <div class="bg-white rounded-lg border border-slate-200 p-5">
                <div class="flex items-center gap-2 text-slate-500">
                    <svg class="h-5 w-5 {{ $overdueCount > 0 ? 'text-red-600' : 'text-amber-500' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                    <span class="text-xs uppercase tracking-wide font-medium">Kios Overdue</span>
                </div>
                <div class="mt-3 text-2xl font-bold {{ $overdueCount > 0 ? 'text-red-600' : 'text-amber-500' }}">
                    {{ $overdueCount }} kios
                </div>
            </div>

            {{-- Total Outstanding --}}
            <div class="bg-white rounded-lg border border-slate-200 p-5">
                <div class="flex items-center gap-2 text-slate-500">
                    <svg class="h-5 w-5 {{ $totalOutstanding > 0 ? 'text-red-600' : 'text-green-600' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span class="text-xs uppercase tracking-wide font-medium">Outstanding</span>
                </div>
                <div class="mt-3 text-2xl font-bold {{ $totalOutstanding > 0 ? 'text-red-600' : 'text-green-600' }}">
                    Rp {{ number_format($totalOutstanding, 0, ',', '.') }}
                </div>
            </div>
        </div>

        <div class="mt-6">
            <a href="{{ route('filament.admin.pages.dashboard') }}"
               class="inline-block bg-amber-600 text-white px-6 py-3 rounded-lg hover:bg-amber-700 font-medium">
                Buka Admin Panel (Manajemen Data)
            </a>
        </div>
    </div>
@endsection
