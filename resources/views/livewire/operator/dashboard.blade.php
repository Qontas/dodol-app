<div class="max-w-md mx-auto space-y-5">
    {{-- Greeting --}}
    <div>
        <h1 class="text-2xl font-bold text-slate-900">Halo, {{ auth()->user()->name }}</h1>
        <p class="text-slate-500 text-sm">{{ now()->locale('id')->isoFormat('dddd, D MMMM Y') }}</p>
    </div>

    {{-- Stats Cards --}}
    <div class="grid grid-cols-2 gap-3">
        <div class="bg-white border border-slate-200 rounded-lg p-4">
            <p class="text-xs text-slate-500 uppercase tracking-wider">Trip Hari Ini</p>
            <p class="text-3xl font-bold mt-1 text-slate-900">{{ $tripsToday }}</p>
        </div>
        <div class="bg-white border border-slate-200 rounded-lg p-4">
            <p class="text-xs text-slate-500 uppercase tracking-wider">Kios Dikunjungi</p>
            <p class="text-3xl font-bold mt-1 text-slate-900">{{ $kiosksVisitedToday }}</p>
        </div>
    </div>

    {{-- CTA Button --}}
    @if ($activeTrip)
        <a href="{{ route('operator.trip.active', $activeTrip->id) }}"
           class="block w-full py-8 bg-green-600 hover:bg-green-700 text-white text-center rounded-2xl font-bold text-2xl shadow-md transition-all">
            <div class="flex flex-col items-center gap-2">
                <svg class="w-12 h-12" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
                </svg>
                <span>Lanjutkan Trip Aktif</span>
                <span class="text-sm font-normal opacity-90">Trip #{{ $activeTrip->trip_number_of_day }} hari ini</span>
            </div>
        </a>
    @else
        <a href="{{ route('operator.trip.start') }}"
           class="block w-full py-8 bg-amber-600 hover:bg-amber-700 text-white text-center rounded-2xl font-bold text-2xl shadow-md transition-all">
            <div class="flex flex-col items-center gap-2">
                <svg class="w-12 h-12" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                </svg>
                <span>Mulai Trip</span>
                <span class="text-sm font-normal opacity-90">Tap untuk mulai ngantar</span>
            </div>
        </a>
    @endif
</div>
