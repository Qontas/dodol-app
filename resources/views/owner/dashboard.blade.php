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

        {{-- Ringkasan Bulan Ini: omset, untung bersih, komisi operator (bulan berjalan) --}}
        @php
            $fmtDelta = function ($pct) {
                if (is_null($pct)) {
                    return ['—', 'text-slate-400', ''];
                }
                $naik = $pct >= 0;
                return [
                    ($naik ? '▲ ' : '▼ ') . number_format(abs($pct), 1, ',', '.') . '%',
                    $naik ? 'text-green-600' : 'text-red-600',
                    'vs bulan lalu',
                ];
            };
        @endphp
        <div class="bg-white rounded-lg border border-slate-200 p-5">
            <div class="flex items-baseline justify-between gap-3 flex-wrap">
                <h3 class="font-bold text-slate-900">Ringkasan Bulan Ini</h3>
                <span class="text-xs font-medium text-slate-500">{{ $ringkasanPeriode }}</span>
            </div>

            <div class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-4">
                {{-- Omset --}}
                @php [$dOmset, $cOmset, $lOmset] = $fmtDelta($ringkasanDelta['omset']); @endphp
                <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                    <div class="text-xs uppercase tracking-wide font-medium text-slate-500">💰 Omset</div>
                    <div class="mt-2 text-2xl font-bold text-green-700">
                        Rp {{ number_format($ringkasanBulanIni['omset'], 0, ',', '.') }}
                    </div>
                    <div class="mt-1 text-xs {{ $cOmset }}">{{ $dOmset }} <span class="text-slate-400">{{ $lOmset }}</span></div>
                </div>

                {{-- Untung Bersih --}}
                @php [$dUntung, $cUntung, $lUntung] = $fmtDelta($ringkasanDelta['untung_bersih']); @endphp
                <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4">
                    <div class="text-xs uppercase tracking-wide font-medium text-emerald-700">📈 Untung Bersih</div>
                    <div class="mt-2 text-2xl font-bold {{ $ringkasanBulanIni['untung_bersih'] >= 0 ? 'text-emerald-700' : 'text-red-600' }}">
                        Rp {{ number_format($ringkasanBulanIni['untung_bersih'], 0, ',', '.') }}
                    </div>
                    <div class="mt-1 text-xs {{ $cUntung }}">{{ $dUntung }} <span class="text-slate-400">{{ $lUntung }}</span></div>
                    <div class="mt-2 text-[11px] text-slate-500 leading-snug">
                        Omset − HPP − komisi. Kerugian BS ditampilkan terpisah.
                    </div>
                </div>

                {{-- Komisi Operator --}}
                @php [$dKomisi, $cKomisi, $lKomisi] = $fmtDelta($ringkasanDelta['komisi']); @endphp
                <div class="rounded-lg border border-amber-200 bg-amber-50 p-4">
                    <div class="text-xs uppercase tracking-wide font-medium text-amber-700">👷 Komisi Operator</div>
                    <div class="mt-2 text-2xl font-bold text-amber-700">
                        Rp {{ number_format($ringkasanBulanIni['komisi'], 0, ',', '.') }}
                    </div>
                    <div class="mt-1 text-xs {{ $cKomisi }}">{{ $dKomisi }} <span class="text-slate-400">{{ $lKomisi }}</span></div>
                    @if ($komisiPerOperator->count() > 1)
                        <div class="mt-2 space-y-0.5">
                            @foreach ($komisiPerOperator as $op)
                                <div class="flex justify-between text-[11px] text-amber-800">
                                    <span class="truncate pr-2">{{ $op['operator'] }}</span>
                                    <span class="font-medium whitespace-nowrap">Rp {{ number_format($op['komisi'], 0, ',', '.') }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
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

        {{-- Belum Bayar per-kios (piutang rupiah dari Tagih+Titip bayar kurang) --}}
        @if ($belumBayarPerKios->isNotEmpty())
            <div class="bg-white rounded-lg border border-red-200 mt-6">
                <div class="px-5 py-4 border-b border-slate-100">
                    <h3 class="font-bold text-slate-900">💰 Belum Bayar (per kios)</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Piutang rupiah dari penagihan yang belum lunas — per kios + janji bayar</p>
                </div>
                <div class="divide-y divide-slate-100">
                    @foreach ($belumBayarPerKios as $bb)
                        <div class="px-5 py-3 flex items-center justify-between gap-3">
                            <div>
                                <p class="text-sm font-medium text-slate-900">{{ $bb['name'] }}</p>
                                @if ($bb['janji'])
                                    <p class="text-xs text-slate-500">{{ $bb['janji'] }}</p>
                                @endif
                            </div>
                            <span class="text-sm font-bold shrink-0 text-red-600">Rp {{ number_format($bb['rupiah'], 0, ',', '.') }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Titipan Tertunda (pemilik belum bisa bayar — janji bayar) --}}
        @if ($titipanTertunda->isNotEmpty())
            <div class="bg-white rounded-lg border border-amber-200 mt-6">
                <div class="px-5 py-4 border-b border-slate-100">
                    <h3 class="font-bold text-slate-900">⏳ Titipan Tertunda</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Kios yang pemiliknya belum bisa bayar — titipan tetap berjalan (ditagih kunjungan berikutnya)</p>
                </div>
                <div class="divide-y divide-slate-100">
                    @foreach ($titipanTertunda as $tt)
                        <div class="px-5 py-3 flex items-center justify-between gap-3">
                            <div>
                                <p class="text-sm font-medium text-slate-900">{{ $tt['name'] }}</p>
                                <p class="text-xs text-slate-500">
                                    {{ $tt['janji'] ?: 'Tanpa catatan janji bayar' }} — sejak {{ $tt['sejak']->translatedFormat('d M Y') }}
                                </p>
                            </div>
                            <span class="text-sm font-bold shrink-0 text-amber-600">{{ $tt['mika'] }} mika</span>
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
                    // Angka finansial dari agregat BATCH (bukan accessor per-baris → hindari N+1).
                    // Identik accessor Trip. Fallback ke accessor kalau map tak ada (aman).
                    $agg = $completedAgg[$completedTrip->id] ?? [
                        'omset' => $completedTrip->omset_val,
                        'mika_terjual' => $completedTrip->mika_terjual,
                        'mika_kios_baru' => $completedTrip->mika_kios_baru,
                        'hpp_estimasi' => $completedTrip->hpp_estimasi,
                        'untung_kotor' => $completedTrip->untung_kotor,
                        'mika_komisi' => $completedTrip->mika_komisi,
                        'komisi' => $completedTrip->komisi_rian,
                        'untung_bersih' => $completedTrip->untung_bersih_owner,
                        'kios_baru_count' => $completedTrip->kios_baru_count,
                        'kios_lama_count' => $completedTrip->kios_lama_count,
                    ];
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
                            <p class="text-xl font-bold text-green-600">Rp {{ number_format($agg['omset'], 0, ',', '.') }}</p>
                            <div class="mt-2 flex justify-end gap-1.5">
                                <a href="{{ route('owner.trips.export.pdf', $completedTrip) }}"
                                   class="rounded bg-red-600 px-2 py-1 text-xs font-semibold text-white hover:bg-red-700">PDF</a>
                                <a href="{{ route('owner.trips.export.excel', $completedTrip) }}"
                                   class="rounded bg-green-600 px-2 py-1 text-xs font-semibold text-white hover:bg-green-700">XLS</a>
                                <form method="POST" action="{{ route('owner.trips.destroy', $completedTrip) }}"
                                      onsubmit="return confirm('Arsipkan Trip #{{ $completedTrip->trip_number_of_day }}? Trip & datanya disembunyikan dari laporan, tapi bisa dipulihkan.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                            class="rounded bg-slate-700 px-2 py-1 text-xs font-semibold text-white hover:bg-slate-800">Arsipkan</button>
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
                            <p class="text-base font-bold text-slate-700">{{ $agg['kios_lama_count'] }} kios</p>
                        </div>
                        <div>
                            <p class="text-[10px] uppercase text-slate-500 font-medium">Kios Baru</p>
                            <p class="text-base font-bold text-amber-600">{{ $agg['kios_baru_count'] }} kios</p>
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
                            <p class="text-base font-bold text-slate-900">{{ number_format($agg['mika_terjual'], 2, ',', '.') }} mika</p>
                        </div>
                        <div>
                            <p class="text-[10px] uppercase text-slate-500 font-medium">Mika Kios Baru (Titip)</p>
                            <p class="text-base font-bold text-amber-700">{{ number_format($agg['mika_kios_baru'], 2, ',', '.') }} mika</p>
                        </div>
                        <div>
                            <p class="text-[10px] uppercase text-slate-500 font-medium">Modal Dodol</p>
                            <p class="text-base font-bold text-red-600">Rp {{ number_format($agg['hpp_estimasi'], 0, ',', '.') }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] uppercase text-slate-500 font-medium">Keuntungan</p>
                            <p class="text-base font-bold text-green-600">Rp {{ number_format($agg['untung_kotor'], 0, ',', '.') }}</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-4 bg-amber-50 border border-amber-100 rounded-lg p-4 text-center mt-3 shadow-inner">
                        <div>
                            <p class="text-[10px] uppercase text-amber-800 font-medium">Mika Komisi (Drop)</p>
                            <p class="text-base font-bold text-amber-700">{{ number_format($agg['mika_komisi'], 2, ',', '.') }} mika</p>
                        </div>
                        <div>
                            <p class="text-[10px] uppercase text-amber-800 font-medium">Komisi Rian</p>
                            <p class="text-base font-bold text-amber-900">Rp {{ number_format($agg['komisi'], 0, ',', '.') }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] uppercase text-emerald-800 font-medium">Untung Bersih Owner</p>
                            <p class="text-base font-bold text-emerald-700 font-sans">Rp {{ number_format($agg['untung_bersih'], 0, ',', '.') }}</p>
                        </div>
                    </div>
                </div>
            @empty
                <div class="text-center py-6 text-slate-500 border border-dashed border-slate-300 rounded-xl bg-slate-50/50">
                    <p class="text-sm">Belum ada trip yang diselesaikan.</p>
                </div>
            @endforelse

            <div class="mt-4 pt-4 border-t border-slate-100 flex items-center justify-end gap-4">
                <a href="{{ route('owner.trips.index') }}"
                   class="inline-flex items-center gap-1 text-sm font-semibold text-amber-700 hover:text-amber-800">
                    Riwayat Semua Trip &rarr;
                </a>
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
