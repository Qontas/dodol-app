@extends('layouts.owner')
@section('title', 'Laporan Bulanan')

@section('content')
    <div class="max-w-6xl mx-auto space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold text-slate-900">Laporan Bulanan</h1>
                <p class="mt-1 text-slate-600">
                    {{ \Illuminate\Support\Carbon::parse($month.'-01')->translatedFormat('F Y') }}
                </p>
            </div>

            <form method="GET" action="{{ route('owner.reports.monthly') }}" class="flex items-end gap-2">
                <div>
                    <label for="month" class="block text-xs font-medium text-slate-600">Pilih Bulan</label>
                    <input type="month" name="month" id="month" value="{{ $month }}"
                           class="mt-1 rounded-md border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                </div>
                <button type="submit" class="rounded-md bg-slate-800 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-900">
                    Tampilkan
                </button>
            </form>
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="{{ route('owner.reports.monthly.pdf', ['month' => $month]) }}"
               class="rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white hover:bg-red-700">
                Export PDF
            </a>
            <a href="{{ route('owner.reports.monthly.excel', ['month' => $month]) }}"
               class="rounded-md bg-green-600 px-3 py-2 text-sm font-semibold text-white hover:bg-green-700">
                Export Excel
            </a>
        </div>

        {{-- Ringkasan analisis kios --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white rounded-lg border border-slate-200 p-4">
                <div class="text-xs uppercase tracking-wide text-slate-500">Kios Di-settle</div>
                <div class="mt-2 text-2xl font-bold text-slate-900">{{ $analisisKios['total_kios_settled'] }}</div>
            </div>
            <div class="bg-white rounded-lg border border-slate-200 p-4">
                <div class="text-xs uppercase tracking-wide text-slate-500">Kios Baru</div>
                <div class="mt-2 text-2xl font-bold text-amber-600">{{ $analisisKios['kios_baru'] }}</div>
            </div>
            <div class="bg-white rounded-lg border border-slate-200 p-4">
                <div class="text-xs uppercase tracking-wide text-slate-500">Total Omset</div>
                <div class="mt-2 text-xl font-bold text-green-600">Rp {{ number_format($totals['omset'], 0, ',', '.') }}</div>
            </div>
            <div class="bg-white rounded-lg border border-slate-200 p-4">
                <div class="text-xs uppercase tracking-wide text-slate-500">Untung Bersih</div>
                <div class="mt-2 text-xl font-bold text-blue-600">Rp {{ number_format($totals['untung_bersih'], 0, ',', '.') }}</div>
            </div>
        </div>

        {{-- Ringkasan Pengiriman & Komisi --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="bg-white rounded-lg border border-slate-200 p-4 shadow-sm">
                <div class="text-xs uppercase tracking-wide text-slate-500 font-semibold">Total Mika Diantar</div>
                <div class="mt-2 text-2xl font-bold text-slate-900">{{ number_format($total_mika_diantar, 0, ',', '.') }} mika</div>
            </div>
            <div class="bg-white rounded-lg border border-slate-200 p-4 shadow-sm">
                <div class="text-xs uppercase tracking-wide text-slate-500 font-semibold">Modal Mika</div>
                <div class="mt-2 text-2xl font-bold text-slate-900">Rp {{ number_format($modal_mika, 0, ',', '.') }}</div>
            </div>
            <div class="bg-white rounded-lg border border-slate-200 p-4 shadow-sm">
                <div class="text-xs uppercase tracking-wide text-slate-500 font-semibold">Rekap Komisi Operator</div>
                <div class="mt-2 text-2xl font-bold text-amber-600">Rp {{ number_format($rekap_komisi, 0, ',', '.') }}</div>
            </div>
        </div>

        {{-- Rekap per trip --}}
        <div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100">
                <h3 class="font-bold text-slate-900">Rekap Per Trip</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-slate-500 text-xs uppercase">
                        <tr>
                            <th class="px-4 py-2 text-left">Tanggal</th>
                            <th class="px-4 py-2 text-left">Operator</th>
                            <th class="px-4 py-2 text-right">Kios</th>
                            <th class="px-4 py-2 text-right">Mika Dititip</th>
                            <th class="px-4 py-2 text-right">Omset</th>
                            <th class="px-4 py-2 text-right">Komisi</th>
                            <th class="px-4 py-2 text-right">Untung Bersih</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($rows as $r)
                            <tr>
                                <td class="px-4 py-2">{{ $r['trip_date'] }}</td>
                                <td class="px-4 py-2">{{ $r['operator'] }}</td>
                                <td class="px-4 py-2 text-right">{{ $r['kios_dikunjungi'] }}</td>
                                <td class="px-4 py-2 text-right">{{ number_format($r['mika_drop'], 0, ',', '.') }}</td>
                                <td class="px-4 py-2 text-right">Rp {{ number_format($r['omset'], 0, ',', '.') }}</td>
                                <td class="px-4 py-2 text-right">Rp {{ number_format($r['komisi'], 0, ',', '.') }}</td>
                                <td class="px-4 py-2 text-right">Rp {{ number_format($r['untung_bersih'], 0, ',', '.') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-6 text-center text-slate-400">Belum ada trip selesai di bulan ini.</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot class="bg-amber-50 font-bold text-slate-900">
                        <tr>
                            <td class="px-4 py-2" colspan="2">TOTAL</td>
                            <td class="px-4 py-2 text-right">{{ $totals['kios_dikunjungi'] }}</td>
                            <td class="px-4 py-2 text-right">{{ number_format($totals['mika_drop'], 0, ',', '.') }}</td>
                            <td class="px-4 py-2 text-right">Rp {{ number_format($totals['omset'], 0, ',', '.') }}</td>
                            <td class="px-4 py-2 text-right">Rp {{ number_format($totals['komisi'], 0, ',', '.') }}</td>
                            <td class="px-4 py-2 text-right">Rp {{ number_format($totals['untung_bersih'], 0, ',', '.') }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
@endsection
