@extends('layouts.owner')
@section('title', 'Dashboard Owner')

@section('content')
    <div class="max-w-6xl mx-auto space-y-6">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">Dashboard Owner</h1>
            <p class="mt-1 text-slate-600">Selamat datang, {{ $user->name }}.</p>
        </div>

        @if (session('status'))
            <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('status') }}
            </div>
        @endif

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white rounded-lg border border-slate-200 p-4">
                <div class="text-xs uppercase tracking-wide text-slate-500">Total Kios</div>
                <div class="mt-2 text-3xl font-bold text-slate-900">{{ $stats['total_kios'] }}</div>
            </div>
            <div class="bg-white rounded-lg border border-slate-200 p-4">
                <div class="text-xs uppercase tracking-wide text-slate-500">Total Area</div>
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
                Database masih kosong. Mulai dengan input area &amp; kios dari menu sidebar.
            </div>
        @endif

        {{-- Widget statistik operasional --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
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
                    <span class="text-xs uppercase tracking-wide font-medium">Kios Perlu Dikunjungi</span>
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
                    <span class="text-xs uppercase tracking-wide font-medium">Belum Bayar</span>
                </div>
                <div class="mt-3 text-2xl font-bold {{ $totalOutstanding > 0 ? 'text-red-600' : 'text-green-600' }}">
                    Rp {{ number_format($totalOutstanding, 0, ',', '.') }}
                </div>
            </div>

            {{-- Total Stok Tersisa --}}
            <div class="bg-white rounded-lg border border-slate-200 p-5">
                <div class="flex items-center gap-2 text-slate-500">
                    <svg class="h-5 w-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                    </svg>
                    <span class="text-xs uppercase tracking-wide font-medium">Total Stok</span>
                </div>
                <div class="mt-3 text-2xl font-bold text-blue-600">
                    {{ $totalStokTersisa }} mika
                </div>
            </div>

            {{-- Untung Bersih Hari Ini --}}
            <div class="bg-white rounded-lg border border-slate-200 p-5">
                <div class="flex items-center gap-2 text-slate-500">
                    <svg class="h-5 w-5 {{ $untungBersihHariIni > 0 ? 'text-green-600' : 'text-slate-400' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 6l9-4 9 4M4 10v8a2 2 0 002 2h12a2 2 0 002-2v-8M9 21V12h6v9"/>
                    </svg>
                    <span class="text-xs uppercase tracking-wide font-medium">Untung Bersih Hari Ini</span>
                </div>
                <div class="mt-3 text-2xl font-bold {{ $untungBersihHariIni > 0 ? 'text-green-600' : 'text-slate-400' }}">
                    Rp {{ number_format($untungBersihHariIni, 0, ',', '.') }}
                </div>
            </div>
        </div>

        {{-- Tabel Stok Per Batch --}}
        @if ($batchStok->count() > 0)
            <div class="bg-white rounded-lg border border-slate-200 mt-6">
                <div class="px-5 py-4 border-b border-slate-100">
                    <h3 class="font-bold text-slate-900">Stok Per Batch</h3>
                </div>
                <div class="divide-y divide-slate-100">
                    @foreach ($batchStok as $batch)
                        <div class="px-5 py-3 flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-slate-900">
                                    Batch {{ \Carbon\Carbon::parse($batch['purchase_date'])->format('d M Y') }}
                                </p>
                                <p class="text-xs text-slate-500">
                                    Total: {{ $batch['qty_packs'] }} mika
                                </p>
                            </div>
                            <div class="text-right">
                                <span class="text-sm font-bold {{ $batch['is_habis'] ? 'text-red-600' : ($batch['is_hampis_habis'] ? 'text-amber-600' : 'text-green-600') }}">
                                    {{ $batch['stok_tersisa'] }} mika
                                </span>
                                @if ($batch['is_habis'])
                                    <p class="text-xs text-red-500">Habis</p>
                                @elseif ($batch['is_hampis_habis'])
                                    <p class="text-xs text-amber-500">Hampir Habis</p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Prediksi Dodol Habis (dari cek sisa biji oleh operator) --}}
        @if ($prediksiKios->isNotEmpty())
            <div class="bg-white rounded-lg border border-slate-200 mt-6">
                <div class="px-5 py-4 border-b border-slate-100">
                    <h3 class="font-bold text-slate-900">Prediksi Dodol Habis</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Dari cek sisa dodol terakhir oleh operator di kios</p>
                </div>
                <div class="divide-y divide-slate-100">
                    @foreach ($prediksiKios as $pk)
                        <div class="px-5 py-3 flex items-center justify-between gap-3">
                            <div>
                                <p class="text-sm font-medium text-slate-900">{{ $pk['name'] }}</p>
                                <p class="text-xs text-slate-500">
                                    Sisa {{ $pk['sisa_biji'] }} biji — dicek {{ $pk['dicek_pada']->translatedFormat('d M Y') }}
                                </p>
                            </div>
                            <span class="text-sm font-bold shrink-0 {{ $pk['prediksi'] && str_contains($pk['prediksi'], 'hari') ? 'text-amber-600' : 'text-slate-400' }}">
                                {{ $pk['prediksi'] ?? 'Data belum cukup' }}
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Kios Berhenti (cut off / stop titipan) --}}
        @if ($stoppedKiosks->isNotEmpty())
            <div class="bg-white rounded-lg border border-slate-200 mt-6">
                <div class="px-5 py-4 border-b border-slate-100">
                    <h3 class="font-bold text-slate-900">Kios Berhenti</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Kios yang dihentikan titipannya — bisa diaktifkan kembali</p>
                    @if ($kerugianTitipan['biji'] > 0)
                        <div class="mt-3 rounded-lg bg-red-50 border border-red-200 px-3 py-2">
                            <p class="text-xs font-bold text-red-700">
                                Kerugian titipan bulan ini (stop tanpa tagih):
                                {{ rtrim(rtrim(number_format($kerugianTitipan['mika'], 1, ',', '.'), '0'), ',') }} mika
                                ({{ number_format($kerugianTitipan['biji'], 0, ',', '.') }} biji)
                                ≈ Rp {{ number_format($kerugianTitipan['nilai'], 0, ',', '.') }}
                            </p>
                            <p class="text-[11px] text-red-500 mt-0.5">Dodol di kedai yang kabur/tak tertagih — dicatat sebagai kerugian (modal × HPP).</p>
                        </div>
                    @endif
                </div>
                @if (session('kiosk_reactivated'))
                    <div class="mx-5 mt-3 rounded-lg bg-emerald-50 border border-emerald-200 px-3 py-2 text-xs text-emerald-700">
                        {{ session('kiosk_reactivated') }}
                    </div>
                @endif
                <div class="divide-y divide-slate-100">
                    @foreach ($stoppedKiosks as $sk)
                        <div class="px-5 py-3 flex items-center justify-between gap-3">
                            <div>
                                <p class="text-sm font-medium text-slate-900">{{ $sk['name'] }}</p>
                                <p class="text-xs text-slate-500">
                                    {{ $sk['reason'] }}
                                    @if ($sk['stopped_at'])
                                        — {{ $sk['stopped_at']->translatedFormat('d M Y') }}
                                    @endif
                                    @if ($sk['stopped_by'])
                                        (oleh {{ ucfirst($sk['stopped_by']) }})
                                    @endif
                                </p>
                            </div>
                            <form method="POST" action="{{ route('owner.kiosks.reactivate', $sk['id']) }}" class="shrink-0">
                                @csrf
                                <button type="submit"
                                    class="text-xs font-bold text-emerald-600 border border-emerald-200 bg-emerald-50 rounded-lg px-3 py-1.5 active:bg-emerald-100">
                                    Aktifkan Kembali
                                </button>
                            </form>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Live Trip Progress --}}
        <livewire:owner.live-trip-progress />

        {{-- Completed Trips Report --}}
        <div class="bg-white rounded-lg border border-slate-200 p-5 mt-6">
            <h2 class="text-sm font-bold text-slate-900 uppercase tracking-wider mb-4 border-b border-slate-100 pb-3">
                Laporan Akhir Trip Terbaru (Selesai)
            </h2>
            
            @forelse ($completedTrips as $completedTrip)
                @php
                    $mikaDrop = $completedTrip->deliveries->sum('qty_delivered');
                @endphp
                <div class="border border-slate-200 rounded-xl p-5 mb-4 last:mb-0 bg-slate-50/50 shadow-sm">
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-4">
                        <div>
                            <span class="inline-flex items-center rounded-md bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600 ring-1 ring-inset ring-slate-500/10">Selesai</span>
                            <span class="text-lg font-bold text-slate-900 ml-1">Trip #{{ $completedTrip->trip_number_of_day }} — {{ $completedTrip->operator->name }}</span>
                            <p class="text-sm text-slate-500 mt-1">
                                Tanggal Trip: <span class="font-semibold text-slate-700">{{ $completedTrip->trip_date ? $completedTrip->trip_date->format('d M Y') : '—' }}</span>
                                | Area: <span class="text-amber-700 font-semibold">{{ $completedTrip->startingCluster->name ?? 'Semua Kios' }}</span> 
                                | Waktu: <span class="font-medium text-slate-700">{{ $completedTrip->started_at ? $completedTrip->started_at->format('H:i') : '—' }} - {{ $completedTrip->ended_at ? $completedTrip->ended_at->format('H:i') : '—' }}</span>
                                @if ($completedTrip->ended_reason)
                                    | Alasan Selesai: <span class="font-medium text-slate-700">
                                        @switch($completedTrip->ended_reason)
                                            @case('stock_habis') Stok Habis @break
                                            @case('target_done') Target Selesai @break
                                            @case('sakit') Sakit @break
                                            @case('urgent_personal') Urusan Pribadi Mendesak @break
                                            @default Lainnya
                                        @endswitch
                                    </span>
                                @endif
                            </p>
                        </div>
                        <div class="text-right">
                            <p class="text-xs text-slate-500">Omset Akhir</p>
                            <p class="text-xl font-bold text-green-600">Rp {{ number_format($completedTrip->omset_val, 0, ',', '.') }}</p>
                            <div class="mt-2 flex justify-end gap-1.5">
                                <a href="{{ route('owner.trips.export.pdf', $completedTrip) }}"
                                   class="rounded bg-red-600 px-2 py-1 text-xs font-semibold text-white hover:bg-red-700">PDF</a>
                                <a href="{{ route('owner.trips.export.excel', $completedTrip) }}"
                                   class="rounded bg-green-600 px-2 py-1 text-xs font-semibold text-white hover:bg-green-700">XLS</a>
                                <form method="POST" action="{{ route('owner.trips.destroy', $completedTrip) }}"
                                      onsubmit="return confirm('Yakin hapus Trip #{{ $completedTrip->trip_number_of_day }}? Data kunjungan akan terhapus permanen.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                            class="rounded bg-slate-700 px-2 py-1 text-xs font-semibold text-white hover:bg-slate-800">Hapus</button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    {{-- Physical Counts Grid --}}
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 bg-white border border-slate-100 rounded-lg p-3 text-center mb-4 shadow-inner">
                        <div>
                            <p class="text-[10px] uppercase text-slate-500 font-medium">Kios Dikunjungi</p>
                            <p class="text-base font-bold text-slate-900">{{ $completedTrip->visits->count() }} kios</p>
                        </div>
                        <div>
                            <p class="text-[10px] uppercase text-slate-500 font-medium">Kios Lama</p>
                            <p class="text-base font-bold text-slate-700">{{ $completedTrip->kios_lama_count }} kios</p>
                        </div>
                        <div>
                            <p class="text-[10px] uppercase text-slate-500 font-medium">Kios Baru</p>
                            <p class="text-base font-bold text-amber-600">{{ $completedTrip->kios_baru_count }} kios</p>
                        </div>
                        <div>
                            <p class="text-[10px] uppercase text-slate-500 font-medium">Total Mika Dititip</p>
                            <p class="text-base font-bold text-slate-900">{{ $mikaDrop }} mika</p>
                        </div>
                    </div>

                    {{-- Financial Report Grid --}}
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 bg-white border border-slate-100 rounded-lg p-4 text-center shadow-inner">
                        <div>
                            <p class="text-[10px] uppercase text-slate-500 font-medium">Mika Terjual</p>
                            <p class="text-base font-bold text-slate-900">{{ number_format($completedTrip->mika_terjual, 2, ',', '.') }} mika</p>
                        </div>
                        <div>
                            <p class="text-[10px] uppercase text-slate-500 font-medium">Mika Kios Baru (Titip)</p>
                            <p class="text-base font-bold text-amber-700">{{ number_format($completedTrip->mika_kios_baru, 2, ',', '.') }} mika</p>
                        </div>
                        <div>
                            <p class="text-[10px] uppercase text-slate-500 font-medium">Modal Dodol</p>
                            <p class="text-base font-bold text-red-600">Rp {{ number_format($completedTrip->hpp_estimasi, 0, ',', '.') }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] uppercase text-slate-500 font-medium">Keuntungan</p>
                            <p class="text-base font-bold text-green-600">Rp {{ number_format($completedTrip->untung_kotor, 0, ',', '.') }}</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 bg-amber-50 border border-amber-100 rounded-lg p-4 text-center mt-3 shadow-inner">
                        <div>
                            <p class="text-[10px] uppercase text-amber-800 font-medium">Komisi Reguler</p>
                            <p class="text-base font-bold text-amber-700">Rp {{ number_format($completedTrip->komisi_reguler, 0, ',', '.') }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] uppercase text-amber-800 font-medium">Komisi Kios Baru</p>
                            <p class="text-base font-bold text-amber-700">Rp {{ number_format($completedTrip->komisi_kios_baru, 0, ',', '.') }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] uppercase text-amber-800 font-medium">Total Komisi Rian</p>
                            <p class="text-base font-bold text-amber-900">Rp {{ number_format($completedTrip->komisi_rian, 0, ',', '.') }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] uppercase text-emerald-800 font-medium">Untung Bersih Owner</p>
                            <p class="text-base font-bold text-emerald-700 font-sans">Rp {{ number_format($completedTrip->untung_bersih_owner, 0, ',', '.') }}</p>
                        </div>
                    </div>
                </div>
            @empty
                <div class="text-center py-6 text-slate-500 border border-dashed border-slate-300 rounded-xl bg-slate-50/50">
                    <p class="text-sm">Belum ada trip yang diselesaikan.</p>
                </div>
            @endforelse

            <div class="mt-4 pt-4 border-t border-slate-100 text-right">
                <a href="{{ route('owner.reports.monthly') }}"
                   class="inline-flex items-center gap-1 text-sm font-semibold text-amber-700 hover:text-amber-800">
                    Lihat Laporan Bulanan &rarr;
                </a>
            </div>
        </div>

        {{-- Chart Omset --}}
        <div class="bg-white rounded-lg border border-slate-200 p-5 mt-6">
            <h2 class="text-sm font-bold text-slate-900 uppercase tracking-wider mb-4">Tren Omset (30 Hari Terakhir)</h2>
            <div class="relative w-full" style="height: 320px;">
                <canvas id="omsetChart"></canvas>
            </div>
        </div>

        <div class="mt-6">
            <a href="{{ route('filament.owner.resources.kiosks.index') }}"
               class="inline-block bg-amber-600 text-white px-6 py-3 rounded-lg hover:bg-amber-700 font-medium">
                Buka Panel Manajemen Data
            </a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            const ctx = document.getElementById('omsetChart').getContext('2d');
            
            const gradient = ctx.createLinearGradient(0, 0, 0, 300);
            gradient.addColorStop(0, 'rgba(217, 119, 6, 0.3)');
            gradient.addColorStop(1, 'rgba(217, 119, 6, 0)');
            
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: {!! json_encode($chartLabels) !!},
                    datasets: [{
                        label: 'Omset',
                        data: {!! json_encode($chartData) !!},
                        borderColor: '#d97706',
                        backgroundColor: gradient,
                        fill: true,
                        tension: 0.3,
                        borderWidth: 2,
                        pointBackgroundColor: '#d97706',
                        pointHoverRadius: 6,
                        pointHoverBackgroundColor: '#fff',
                        pointHoverBorderColor: '#d97706',
                        pointHoverBorderWidth: 2,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    if (context.parsed.y !== null) {
                                        label += new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(context.parsed.y);
                                    }
                                    return label;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return 'Rp ' + new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(value);
                                },
                                font: {
                                    size: 10
                                }
                            },
                            grid: {
                                color: '#f1f5f9'
                            }
                        },
                        x: {
                            ticks: {
                                font: {
                                    size: 10
                                }
                            },
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        });
    </script>
@endsection
