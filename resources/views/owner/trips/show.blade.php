@extends('layouts.owner')
@section('title', 'Detail Trip')

@section('content')
    <div class="max-w-5xl mx-auto space-y-6">
        <div>
            <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('owner.trips.index') }}"
               class="text-sm text-amber-700 hover:text-amber-800">&larr; Kembali ke Riwayat Trip</a>
        </div>

        @if (session('status'))
            <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('status') }}
            </div>
        @endif

        {{-- Header trip --}}
        <div class="bg-white rounded-lg border border-slate-200 p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-xl font-semibold text-slate-900">Trip {{ $trip->trip_date->format('d M Y') }}</h1>
                        @if ($trip->trashed())
                            <span class="inline-flex rounded-full bg-slate-200 px-2 py-0.5 text-xs font-medium text-slate-600">Diarsip</span>
                        @else
                            <span class="inline-flex rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700">Aktif</span>
                        @endif
                    </div>
                    <p class="mt-1 text-sm text-slate-600">
                        Trip #{{ $trip->trip_number_of_day }} &middot;
                        Operator: <span class="font-medium text-slate-800">{{ $trip->operator?->name ?? '—' }}</span> &middot;
                        {{-- Label area JUJUR: ikut menyebut area yang diseberangi
                             (operator boleh pindah area di tengah trip). --}}
                        Area: <span class="{{ $area['crossed'] ? 'font-medium text-sky-800' : '' }}">{{ $area['label'] }}</span>
                    </p>
                    @if ($area['crossed'])
                        <p class="mt-1 text-xs text-sky-700">
                            Trip ini menyeberang area — kunjungan tercatat di
                            {{ count($area['visited']) }} area, semuanya dalam SATU trip
                            (komisi &amp; stok tetap dihitung sebagai satu trip).
                        </p>
                    @endif
                </div>
                <div class="flex flex-wrap items-center gap-1.5">
                    <a href="{{ route('owner.trips.export.pdf', $trip) }}"
                       class="rounded bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700">Export PDF</a>
                    <a href="{{ route('owner.trips.export.excel', $trip) }}"
                       class="rounded bg-green-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-green-700">Export Excel</a>
                    @if ($trip->trashed())
                        <form method="POST" action="{{ route('owner.trips.restore', $trip) }}"
                              onsubmit="return confirm('Pulihkan trip ini? Akan kembali dihitung di laporan.');">
                            @csrf
                            <button type="submit" class="rounded bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700">Pulihkan</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('owner.trips.destroy', $trip) }}"
                              onsubmit="return confirm('Arsipkan Trip #{{ $trip->trip_number_of_day }}? Trip & datanya disembunyikan dari laporan, tapi bisa dipulihkan.');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="rounded bg-slate-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-800">Arsipkan</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        {{-- Ringkasan barang --}}
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 bg-white border border-slate-200 rounded-lg p-4 text-center">
            <div>
                <p class="text-[10px] uppercase text-slate-500 font-medium">Mika Dibawa</p>
                <p class="text-base font-bold text-slate-900">{{ number_format($summary['mika_dibawa'], 0, ',', '.') }} mika</p>
            </div>
            <div>
                <p class="text-[10px] uppercase text-slate-500 font-medium">Mika Diantar</p>
                <p class="text-base font-bold text-slate-900">{{ number_format($summary['mika_drop'], 0, ',', '.') }} mika</p>
            </div>
            <div>
                <p class="text-[10px] uppercase text-slate-500 font-medium">Mika Sisa</p>
                <p class="text-base font-bold text-slate-700">{{ number_format($summary['mika_sisa'], 0, ',', '.') }} mika</p>
            </div>
            <div>
                <p class="text-[10px] uppercase text-slate-500 font-medium">Mika Terjual</p>
                <p class="text-base font-bold text-slate-900">{{ number_format($trip->mika_terjual, 2, ',', '.') }} mika</p>
            </div>
        </div>

        {{-- Ringkasan finansial --}}
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 bg-white border border-slate-200 rounded-lg p-4 text-center">
            <div>
                <p class="text-[10px] uppercase text-slate-500 font-medium">Omset</p>
                <p class="text-base font-bold text-green-600">Rp {{ number_format($trip->omset_val, 0, ',', '.') }}</p>
            </div>
            <div>
                <p class="text-[10px] uppercase text-slate-500 font-medium">Modal Dodol (HPP)</p>
                <p class="text-base font-bold text-red-600">Rp {{ number_format($trip->hpp_estimasi, 0, ',', '.') }}</p>
            </div>
            <div>
                <p class="text-[10px] uppercase text-slate-500 font-medium">Untung Kotor</p>
                <p class="text-base font-bold text-slate-900">Rp {{ number_format($trip->untung_kotor, 0, ',', '.') }}</p>
            </div>
            <div>
                <p class="text-[10px] uppercase text-slate-500 font-medium">Mika Komisi (Drop)</p>
                <p class="text-base font-bold text-amber-700">{{ number_format($trip->mika_komisi, 2, ',', '.') }} mika</p>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4 bg-amber-50 border border-amber-100 rounded-lg p-4 text-center">
            <div>
                <p class="text-[10px] uppercase text-amber-800 font-medium">Komisi Rian</p>
                <p class="text-base font-bold text-amber-900">Rp {{ number_format($trip->komisi_rian, 0, ',', '.') }}</p>
            </div>
            <div>
                <p class="text-[10px] uppercase text-emerald-800 font-medium">Untung Bersih Owner</p>
                <p class="text-base font-bold text-emerald-700">Rp {{ number_format($trip->untung_bersih_owner, 0, ',', '.') }}</p>
            </div>
        </div>

        {{-- Daftar kunjungan kios --}}
        <div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-100">
                <h2 class="text-sm font-bold text-slate-900 uppercase tracking-wider">Kunjungan Kios ({{ $visitRows->count() }})</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium">Kios</th>
                            <th class="px-4 py-3 text-left font-medium">Aksi</th>
                            <th class="px-4 py-3 text-left font-medium">Waktu</th>
                            <th class="px-4 py-3 text-right font-medium">Mika Titip</th>
                            <th class="px-4 py-3 text-right font-medium">BS (biji)</th>
                            <th class="px-4 py-3 text-right font-medium">Uang</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($visitRows as $v)
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3 text-slate-800">{{ $v['kiosk'] }}</td>
                                <td class="px-4 py-3 text-slate-600">{{ $v['action'] }}</td>
                                <td class="px-4 py-3 whitespace-nowrap text-slate-500">{{ $v['time'] }}</td>
                                <td class="px-4 py-3 text-right text-slate-700">{{ $v['mika_titip'] ? number_format($v['mika_titip'], 0, ',', '.') : '—' }}</td>
                                <td class="px-4 py-3 text-right text-red-600">{{ $v['bs_biji'] ? number_format($v['bs_biji'], 0, ',', '.') : '—' }}</td>
                                <td class="px-4 py-3 text-right text-slate-900 font-medium">{{ $v['uang'] ? 'Rp ' . number_format($v['uang'], 0, ',', '.') : '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center text-slate-500">Tidak ada kunjungan pada trip ini.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
