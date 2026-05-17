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

        <div class="mt-6">
            <a href="{{ route('filament.admin.pages.dashboard') }}"
               class="inline-block bg-amber-600 text-white px-6 py-3 rounded-lg hover:bg-amber-700 font-medium">
                Buka Admin Panel (Manajemen Data)
            </a>
        </div>
    </div>
@endsection
