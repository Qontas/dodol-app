<div class="max-w-md mx-auto">
    {{-- Header --}}
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-slate-900">Trip Aktif</h1>
        @if ($startingCluster)
            <p class="text-slate-600 text-sm">
                Cluster awal: <span class="text-amber-700 font-medium">{{ $startingCluster->name }}</span>
            </p>
        @endif
        <p class="text-xs text-slate-500 mt-1">
            Trip #{{ $trip->trip_number_of_day }} hari ini &middot; Mulai {{ $trip->started_at->locale('id')->isoFormat('D MMM Y, HH:mm') }}
        </p>
    </div>

    {{-- Skeleton State --}}
    <div class="bg-white border border-slate-200 rounded-xl p-6 text-center">
        <svg class="mx-auto h-16 w-16 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17a2 2 0 11-4 0 2 2 0 014 0zM19 17a2 2 0 11-4 0 2 2 0 014 0z"/>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0"/>
        </svg>
        <h3 class="mt-4 text-lg font-bold text-slate-900">Trip baru dimulai!</h3>
        <p class="mt-2 text-sm text-slate-600">
            Trip ID: <span class="font-mono">#{{ $trip->id }}</span>
        </p>
    </div>

    {{-- Coming Soon List --}}
    <div class="mt-6 space-y-2">
        <h4 class="text-sm font-semibold text-slate-500 uppercase tracking-wider mb-3">Coming Soon (Day 6+)</h4>

        <div class="bg-white border border-slate-200 rounded-lg p-3 flex items-center gap-3 opacity-70">
            <span class="text-2xl">📍</span>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-slate-700">List Kios di Cluster</p>
                <p class="text-xs text-slate-500">Nearest Neighbor + drag-drop reorder</p>
            </div>
        </div>

        <div class="bg-white border border-slate-200 rounded-lg p-3 flex items-center gap-3 opacity-70">
            <span class="text-2xl">📦</span>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-slate-700">Form Visit per Kios</p>
                <p class="text-xs text-slate-500">drop_and_settle / drop_only / check_only / settle_only</p>
            </div>
        </div>

        <div class="bg-white border border-slate-200 rounded-lg p-3 flex items-center gap-3 opacity-70">
            <span class="text-2xl">🔄</span>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-slate-700">Lanjut Cluster Lain</p>
                <p class="text-xs text-slate-500">Sequential multi-cluster trip</p>
            </div>
        </div>

        <div class="bg-white border border-slate-200 rounded-lg p-3 flex items-center gap-3 opacity-70">
            <span class="text-2xl">✅</span>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-slate-700">End Trip + Summary</p>
                <p class="text-xs text-slate-500">stock_habis / target_done / sakit / urgent</p>
            </div>
        </div>
    </div>

    {{-- Temporary Back to Dashboard (untuk testing Sesi 1) --}}
    <div class="mt-8 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
        <p class="text-xs text-yellow-700 mb-2">⚠️ Temporary (untuk testing Sesi 1)</p>
        <a href="{{ route('operator.dashboard') }}" class="text-sm text-yellow-800 underline font-medium">
            Kembali ke Dashboard tanpa end trip
        </a>
        <p class="text-xs text-slate-500 mt-1">Trip tetap aktif di DB, bisa dilanjutkan</p>
    </div>
</div>
