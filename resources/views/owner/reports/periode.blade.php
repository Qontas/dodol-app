@extends('layouts.owner')
@section('title', 'Laporan Periode')

@section('content')
    <div class="max-w-6xl mx-auto space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold text-slate-900">Laporan Periode</h1>
                <p class="mt-1 text-slate-600">{{ $label }}</p>
            </div>
            <a href="{{ route('owner.reports.monthly') }}"
               class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 whitespace-nowrap">
                Laporan Bulanan (lama) &rarr;
            </a>
        </div>

        {{-- Quick-pick rentang tanggal --}}
        <div class="flex flex-wrap gap-2">
            @foreach ([['today', 'Hari Ini'], ['week', 'Minggu Ini'], ['month', 'Bulan Ini']] as [$key, $text])
                <a href="{{ route('owner.reports.periode', array_filter(['range' => $key, 'operator_id' => $operatorId])) }}"
                   class="rounded-md px-3 py-2 text-sm font-semibold {{ $range === $key ? 'bg-amber-600 text-white' : 'bg-white border border-slate-300 text-slate-700 hover:bg-slate-50' }}">
                    {{ $text }}
                </a>
            @endforeach
            <span class="rounded-md px-3 py-2 text-sm font-semibold {{ $range === 'custom' ? 'bg-amber-600 text-white' : 'bg-white border border-slate-300 text-slate-700' }}">
                Custom
            </span>
        </div>

        {{-- Form rentang custom + filter operator --}}
        <form method="GET" action="{{ route('owner.reports.periode') }}" class="flex flex-wrap items-end gap-2 bg-white border border-slate-200 rounded-lg p-3">
            <input type="hidden" name="range" value="custom">
            <div>
                <label for="from" class="block text-xs font-medium text-slate-600">Dari</label>
                <input type="date" name="from" id="from" value="{{ $startDate }}"
                       class="mt-1 rounded-md border-slate-300 shadow-sm text-sm focus:border-amber-500 focus:ring-amber-500">
            </div>
            <div>
                <label for="to" class="block text-xs font-medium text-slate-600">Sampai</label>
                <input type="date" name="to" id="to" value="{{ $endDate }}"
                       class="mt-1 rounded-md border-slate-300 shadow-sm text-sm focus:border-amber-500 focus:ring-amber-500">
            </div>
            <div>
                <label for="operator_id" class="block text-xs font-medium text-slate-600">Operator</label>
                <select name="operator_id" id="operator_id"
                        class="mt-1 rounded-md border-slate-300 shadow-sm text-sm focus:border-amber-500 focus:ring-amber-500">
                    <option value="">Semua Operator</option>
                    @foreach ($operators as $op)
                        <option value="{{ $op->id }}" @selected((int) $operatorId === $op->id)>{{ $op->name }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="rounded-md bg-slate-800 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-900">
                Tampilkan
            </button>
        </form>

        {{-- Export --}}
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('owner.reports.periode.pdf', ['range' => $range, 'from' => $startDate, 'to' => $endDate, 'operator_id' => $operatorId]) }}" data-force-download
               class="rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white hover:bg-red-700">
                Export PDF
            </a>
            <a href="{{ route('owner.reports.periode.excel', ['range' => $range, 'from' => $startDate, 'to' => $endDate, 'operator_id' => $operatorId]) }}" data-force-download
               class="rounded-md bg-green-600 px-3 py-2 text-sm font-semibold text-white hover:bg-green-700">
                Export Excel
            </a>
        </div>

        {{-- Ringkasan --}}
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <div class="bg-white rounded-lg border border-slate-200 p-4">
                <div class="text-xs uppercase tracking-wide text-slate-500">Omset</div>
                <div class="mt-2 text-xl font-bold text-green-600">Rp {{ number_format($totals['omset'], 0, ',', '.') }}</div>
            </div>
            <div class="bg-white rounded-lg border border-slate-200 p-4">
                <div class="text-xs uppercase tracking-wide text-slate-500">HPP</div>
                <div class="mt-2 text-xl font-bold text-red-600">Rp {{ number_format($totals['hpp'], 0, ',', '.') }}</div>
            </div>
            <div class="bg-white rounded-lg border border-slate-200 p-4">
                <div class="text-xs uppercase tracking-wide text-slate-500">Untung Kotor</div>
                <div class="mt-2 text-xl font-bold text-slate-900">Rp {{ number_format($totals['untung_kotor'], 0, ',', '.') }}</div>
            </div>
            <div class="bg-white rounded-lg border border-slate-200 p-4">
                <div class="text-xs uppercase tracking-wide text-slate-500">Komisi</div>
                <div class="mt-2 text-xl font-bold text-amber-600">Rp {{ number_format($totals['komisi'], 0, ',', '.') }}</div>
            </div>
            <div class="bg-white rounded-lg border border-slate-200 p-4">
                <div class="text-xs uppercase tracking-wide text-slate-500">Untung Bersih</div>
                <div class="mt-2 text-xl font-bold text-blue-600">Rp {{ number_format($totals['untung_bersih'], 0, ',', '.') }}</div>
            </div>
            <div class="bg-white rounded-lg border border-slate-200 p-4">
                <div class="text-xs uppercase tracking-wide text-slate-500">Mika Diantar</div>
                <div class="mt-2 text-xl font-bold text-slate-900">{{ number_format($totals['mika_diantar'], 0, ',', '.') }}</div>
            </div>
            <div class="bg-white rounded-lg border border-slate-200 p-4">
                <div class="text-xs uppercase tracking-wide text-slate-500">Kios Dikunjungi</div>
                <div class="mt-2 text-xl font-bold text-slate-900">{{ number_format($totals['kios_dikunjungi'], 0, ',', '.') }}</div>
            </div>
            <div class="bg-white rounded-lg border border-slate-200 p-4">
                <div class="text-xs uppercase tracking-wide text-slate-500">Piutang Baru</div>
                <div class="mt-2 text-xl font-bold text-rose-600">Rp {{ number_format($totals['piutang_baru'], 0, ',', '.') }}</div>
            </div>
        </div>

        {{-- Per trip --}}
        <div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100">
                <h3 class="font-bold text-slate-900">Per Trip ({{ $trips->count() }})</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-slate-500 text-xs uppercase">
                        <tr>
                            <th class="px-4 py-2 text-left">Tanggal</th>
                            <th class="px-4 py-2 text-left">Operator</th>
                            <th class="px-4 py-2 text-right">Kios</th>
                            <th class="px-4 py-2 text-right">Mika</th>
                            <th class="px-4 py-2 text-right">Omset</th>
                            <th class="px-4 py-2 text-right">Komisi</th>
                            <th class="px-4 py-2 text-right">Untung Bersih</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($trips as $r)
                            <tr>
                                <td class="px-4 py-2">{{ $r['trip_date'] }}</td>
                                <td class="px-4 py-2">{{ $r['operator'] }}</td>
                                <td class="px-4 py-2 text-right">{{ $r['kios_dikunjungi'] }}</td>
                                <td class="px-4 py-2 text-right">{{ number_format($r['mika_diantar'], 0, ',', '.') }}</td>
                                <td class="px-4 py-2 text-right">Rp {{ number_format($r['omset'], 0, ',', '.') }}</td>
                                <td class="px-4 py-2 text-right">Rp {{ number_format($r['komisi'], 0, ',', '.') }}</td>
                                <td class="px-4 py-2 text-right">Rp {{ number_format($r['untung_bersih'], 0, ',', '.') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-6 text-center text-slate-400">Tidak ada trip pada periode ini.</td></tr>
                        @endforelse
                    </tbody>
                    @if ($trips->count() > 0)
                        <tfoot class="bg-amber-50 font-bold text-slate-900">
                            <tr>
                                <td class="px-4 py-2" colspan="2">TOTAL</td>
                                <td class="px-4 py-2 text-right">{{ $trips->sum('kios_dikunjungi') }}</td>
                                <td class="px-4 py-2 text-right">{{ number_format($trips->sum('mika_diantar'), 0, ',', '.') }}</td>
                                <td class="px-4 py-2 text-right">Rp {{ number_format($trips->sum('omset'), 0, ',', '.') }}</td>
                                <td class="px-4 py-2 text-right">Rp {{ number_format($trips->sum('komisi'), 0, ',', '.') }}</td>
                                <td class="px-4 py-2 text-right">Rp {{ number_format($trips->sum('untung_bersih'), 0, ',', '.') }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>

        {{-- Per operator --}}
        <div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100">
                <h3 class="font-bold text-slate-900">Per Operator</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-slate-500 text-xs uppercase">
                        <tr>
                            <th class="px-4 py-2 text-left">Operator</th>
                            <th class="px-4 py-2 text-right">Trip</th>
                            <th class="px-4 py-2 text-right">Kios</th>
                            <th class="px-4 py-2 text-right">Omset</th>
                            <th class="px-4 py-2 text-right">Komisi</th>
                            <th class="px-4 py-2 text-right">Untung Bersih</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($perOperator as $o)
                            <tr>
                                <td class="px-4 py-2">{{ $o['operator'] }}</td>
                                <td class="px-4 py-2 text-right">{{ $o['trip_count'] }}</td>
                                <td class="px-4 py-2 text-right">{{ $o['kios_dikunjungi'] }}</td>
                                <td class="px-4 py-2 text-right">Rp {{ number_format($o['omset'], 0, ',', '.') }}</td>
                                <td class="px-4 py-2 text-right">Rp {{ number_format($o['komisi'], 0, ',', '.') }}</td>
                                <td class="px-4 py-2 text-right">Rp {{ number_format($o['untung_bersih'], 0, ',', '.') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-6 text-center text-slate-400">Tidak ada data operator.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
