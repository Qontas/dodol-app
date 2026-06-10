<div wire:poll.30s class="space-y-6">
    <div class="flex items-center justify-between mb-4 border-b border-slate-100 pb-3">
        <h2 class="text-sm font-bold text-slate-900 uppercase tracking-wider flex items-center gap-2">
            Laporan Trip Real-Time (Live)
        </h2>
        <span class="inline-flex items-center gap-1.5 rounded-full bg-green-50 px-2 py-1 text-xs font-semibold text-green-700 ring-1 ring-inset ring-green-600/20">
            <span class="h-1.5 w-1.5 rounded-full bg-green-600 animate-ping"></span>
            Live Polling (30s)
        </span>
    </div>
    
    @forelse ($activeTrips as $activeTrip)
        @php
            $stats = $tripStats[$activeTrip->id];

            $visitedKios = $activeTrip->visits->count();
            $totalKios = $stats['total_kios'];

            $progressPercent = min(100, max(0, $totalKios > 0 ? ($visitedKios / $totalKios) * 100 : 0));

            $unvisitedKiosks = $stats['unvisited_kiosks'];

            $totalDrop = (int) $activeTrip->deliveries->sum('qty_delivered');
        @endphp
        <div class="border border-slate-200 rounded-xl p-5 bg-slate-50/50 shadow-sm">
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-4">
                <div>
                    <span class="text-lg font-bold text-slate-900">Trip #{{ $activeTrip->trip_number_of_day }} — {{ $activeTrip->operator->name }}</span>
                    <p class="text-sm text-slate-500 mt-1">
                        Area: <span class="text-amber-700 font-semibold">{{ $activeTrip->startingCluster->name ?? 'Semua Kios' }}</span> 
                        | Mulai: <span class="font-medium text-slate-700">{{ $activeTrip->started_at ? $activeTrip->started_at->format('H:i') : '—' }}</span>
                        | Bawa: <span class="font-medium text-slate-700">{{ $activeTrip->qty_carried_total }} mika</span>
                    </p>
                </div>
                <div class="text-right">
                    <p class="text-xs text-slate-500">Omset Sementara</p>
                    <p class="text-xl font-bold text-green-600">Rp {{ number_format($stats['omset'], 0, ',', '.') }}</p>
                </div>
            </div>
            
            {{-- Progress Status --}}
            <div class="mb-4">
                <div class="flex justify-between text-xs font-semibold text-slate-700 mb-1">
                    <span>{{ $activeTrip->operator->name }} sudah visit {{ $visitedKios }} dari {{ $totalKios }} kios</span>
                    <span>{{ round($progressPercent) }}%</span>
                </div>
                <div class="w-full bg-slate-200 rounded-full h-2">
                    <div class="bg-amber-600 h-2 rounded-full transition-all duration-300" style="width: {{ $progressPercent }}%"></div>
                </div>
            </div>
            
            {{-- Running Totals --}}
            <div class="grid grid-cols-3 gap-4 bg-white border border-slate-100 rounded-lg p-3 text-center mb-4 shadow-inner">
                <div>
                    <p class="text-[10px] uppercase text-slate-500 font-medium">Mika Drop</p>
                    <p class="text-base font-bold text-slate-900">{{ $totalDrop }} mika</p>
                </div>
                <div>
                    <p class="text-[10px] uppercase text-slate-500 font-medium">Omset</p>
                    <p class="text-base font-bold text-green-600">Rp {{ number_format($stats['omset'], 0, ',', '.') }}</p>
                </div>
                <div>
                    <p class="text-[10px] uppercase text-slate-500 font-medium">Komisi Sejauh Ini</p>
                    <p class="text-base font-bold text-amber-600">Rp {{ number_format($stats['komisi'], 0, ',', '.') }}</p>
                </div>
            </div>
            
            {{-- Lists of Kiosks --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-4">
                {{-- Visited Kiosks --}}
                <div>
                    <p class="text-xs font-bold text-slate-800 uppercase tracking-wider mb-2">Kios Sudah Dikunjungi ({{ $visitedKios }})</p>
                    @if ($activeTrip->visits->isNotEmpty())
                        <div class="relative pl-4 border-l border-slate-200 space-y-3">
                            @foreach ($stats['sorted_visits'] as $visit)
                                <div class="relative">
                                    <div class="absolute -left-[21px] top-1.5 bg-green-600 rounded-full w-2 h-2 border border-white"></div>
                                    <div class="text-xs">
                                        <span class="font-semibold text-slate-500">{{ $visit->visited_at ? $visit->visited_at->format('H:i') : '—' }}</span>
                                        <span class="font-bold text-slate-900 ml-1">{{ $visit->kiosk->name }}</span>
                                        <span class="ml-2 inline-flex items-center rounded-md px-1.5 py-0.5 text-[10px] font-medium
                                            {{ $visit->visit_action === 'drop_and_settle' ? 'bg-green-50 text-green-700 ring-1 ring-inset ring-green-600/20' : '' }}
                                            {{ $visit->visit_action === 'drop_only' ? 'bg-blue-50 text-blue-700 ring-1 ring-inset ring-blue-700/10' : '' }}
                                            {{ $visit->visit_action === 'settle_only' ? 'bg-amber-50 text-amber-800 ring-1 ring-inset ring-amber-600/20' : '' }}
                                            {{ $visit->visit_action === 'cash_sale' ? 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-600/20' : '' }}
                                            {{ $visit->visit_action === 'check_only' ? 'bg-slate-50 text-slate-600 ring-1 ring-inset ring-slate-500/10' : '' }}
                                        ">
                                            {{ \App\Http\Controllers\Owner\TripExportController::visitActionLabel($visit->visit_action) }}
                                        </span>
                                        
                                        @if ($visit->newDelivery || ($visit->settledDelivery && $visit->settledDelivery->settlement))
                                            <p class="text-[11px] text-slate-600 mt-0.5">
                                                @if ($visit->newDelivery)
                                                    Drop: <span class="font-semibold text-slate-800">{{ $visit->newDelivery->qty_delivered }} mika</span>
                                                @endif
                                                @if ($visit->settledDelivery && $visit->settledDelivery->settlement)
                                                    @if ($visit->newDelivery) | @endif
                                                    Settle: <span class="font-semibold text-slate-800">Rp {{ number_format($visit->settledDelivery->settlement->amount_paid, 0, ',', '.') }}</span>
                                                    (Terjual: <span class="font-semibold text-slate-800">{{ $visit->settledDelivery->settlement->qty_sold }} biji</span>)
                                                @endif
                                            </p>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-xs text-slate-400 italic">Belum ada kios dikunjungi.</p>
                    @endif
                </div>
                
                {{-- Unvisited Kiosks --}}
                <div>
                    <p class="text-xs font-bold text-slate-800 uppercase tracking-wider mb-2">Kios Belum Dikunjungi ({{ $unvisitedKiosks->count() }})</p>
                    <div class="flex flex-wrap gap-2">
                        @forelse ($unvisitedKiosks as $unvisitedKiosk)
                            <span class="inline-flex items-center rounded-md bg-white border border-slate-200 px-2.5 py-1.5 text-xs font-medium text-slate-600 shadow-sm">
                                {{ $unvisitedKiosk->name }}
                            </span>
                        @empty
                            <span class="text-xs text-slate-400 italic">Semua kios sudah dikunjungi!</span>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    @empty
        <div class="text-center py-8 text-slate-500 border border-dashed border-slate-300 rounded-xl bg-slate-50/50">
            <p class="text-sm">Tidak ada trip aktif saat ini.</p>
            <p class="text-xs text-slate-400 mt-1">Status dan aktivitas Rian akan muncul di sini secara real-time begitu ia memulai trip.</p>
        </div>
    @endforelse
</div>
