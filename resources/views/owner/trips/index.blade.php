@extends('layouts.owner')
@section('title', 'Riwayat Trip')

@section('content')
    <div class="max-w-6xl mx-auto space-y-6">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">Riwayat Trip</h1>
            <p class="mt-1 text-slate-600">Semua trip yang sudah selesai — filter, buka detail, kelola arsip.</p>
        </div>

        @if (session('status'))
            <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('status') }}
            </div>
        @endif

        {{-- Filter (server-side, GET) --}}
        <form method="GET" action="{{ route('owner.trips.index') }}"
              class="bg-white rounded-lg border border-slate-200 p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 items-end">
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Status</label>
                <select name="status" class="w-full rounded-md border-slate-300 text-sm">
                    <option value="aktif" @selected($filters['status'] === 'aktif')>Aktif</option>
                    <option value="diarsip" @selected($filters['status'] === 'diarsip')>Diarsip</option>
                    <option value="semua" @selected($filters['status'] === 'semua')>Semua</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Operator</label>
                <select name="operator_id" class="w-full rounded-md border-slate-300 text-sm">
                    <option value="">Semua operator</option>
                    @foreach ($operators as $op)
                        <option value="{{ $op->id }}" @selected((string) $filters['operator_id'] === (string) $op->id)>{{ $op->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Dari tanggal</label>
                <input type="date" name="from" value="{{ $filters['from'] }}" class="w-full rounded-md border-slate-300 text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Sampai tanggal</label>
                <input type="date" name="to" value="{{ $filters['to'] }}" class="w-full rounded-md border-slate-300 text-sm">
            </div>
            <div class="flex gap-2">
                <button type="submit" class="flex-1 rounded-md bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700">Terapkan</button>
                <a href="{{ route('owner.trips.index') }}" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50">Reset</a>
            </div>
        </form>

        {{-- Tabel trip --}}
        <div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium">Tanggal</th>
                            <th class="px-4 py-3 text-left font-medium">Operator</th>
                            <th class="px-4 py-3 text-left font-medium">Area</th>
                            <th class="px-4 py-3 text-right font-medium">Kios</th>
                            <th class="px-4 py-3 text-right font-medium">Mika Diantar</th>
                            <th class="px-4 py-3 text-right font-medium">Omset</th>
                            <th class="px-4 py-3 text-right font-medium">Komisi</th>
                            <th class="px-4 py-3 text-right font-medium">Untung Bersih</th>
                            <th class="px-4 py-3 text-center font-medium">Status</th>
                            <th class="px-4 py-3 text-right font-medium">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($trips as $trip)
                            @php
                                $agg = $aggregates[$trip->id] ?? ['kios' => 0, 'mika_diantar' => 0, 'omset' => 0, 'komisi' => 0, 'untung_bersih' => 0];
                                $area = $areas[$trip->id] ?? ['label' => 'Semua Kios', 'crossed' => false];
                            @endphp
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <a href="{{ route('owner.trips.show', $trip) }}" class="font-medium text-amber-700 hover:text-amber-800">
                                        {{ $trip->trip_date->format('d M Y') }}
                                    </a>
                                    <span class="block text-xs text-slate-400">Trip #{{ $trip->trip_number_of_day }}</span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-slate-700">{{ $trip->operator?->name ?? '—' }}</td>
                                {{-- Area JUJUR: kalau trip menyeberang, labelnya menyebutkannya.
                                     Jangan pernah tertulis "Kota 1" polos untuk trip yang
                                     kunjungannya juga ada di Pancing. --}}
                                <td class="px-4 py-3 text-slate-700">
                                    <span class="{{ $area['crossed'] ? 'font-medium text-sky-800' : '' }}">{{ $area['label'] }}</span>
                                    @if ($area['crossed'])
                                        <span class="ml-1 inline-flex rounded-full bg-sky-100 px-1.5 py-0.5 text-[10px] font-semibold text-sky-800 align-middle">lintas</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right text-slate-700">{{ $agg['kios'] }}</td>
                                <td class="px-4 py-3 text-right text-slate-700">{{ number_format($agg['mika_diantar'], 0, ',', '.') }}</td>
                                <td class="px-4 py-3 text-right text-slate-900 font-medium">Rp {{ number_format($agg['omset'], 0, ',', '.') }}</td>
                                <td class="px-4 py-3 text-right text-amber-800">Rp {{ number_format($agg['komisi'], 0, ',', '.') }}</td>
                                <td class="px-4 py-3 text-right font-semibold text-emerald-700">Rp {{ number_format($agg['untung_bersih'], 0, ',', '.') }}</td>
                                <td class="px-4 py-3 text-center">
                                    @if ($trip->trashed())
                                        <span class="inline-flex rounded-full bg-slate-200 px-2 py-0.5 text-xs font-medium text-slate-600">Diarsip</span>
                                    @else
                                        <span class="inline-flex rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700">Aktif</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end gap-1.5">
                                        <a href="{{ route('owner.trips.show', $trip) }}"
                                           class="rounded bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-200">Detail</a>
                                        @if ($trip->trashed())
                                            <form method="POST" action="{{ route('owner.trips.restore', $trip) }}"
                                                  onsubmit="return confirm('Pulihkan trip ini? Akan kembali dihitung di laporan.');">
                                                @csrf
                                                <button type="submit"
                                                        class="rounded bg-emerald-600 px-2 py-1 text-xs font-semibold text-white hover:bg-emerald-700">Pulihkan</button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('owner.trips.destroy', $trip) }}"
                                                  onsubmit="return confirm('Arsipkan Trip #{{ $trip->trip_number_of_day }}? Trip & datanya disembunyikan dari laporan, tapi bisa dipulihkan.');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit"
                                                        class="rounded bg-slate-700 px-2 py-1 text-xs font-semibold text-white hover:bg-slate-800">Arsipkan</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="px-4 py-10 text-center text-slate-500">
                                    Tidak ada trip yang cocok dengan filter.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div>
            {{ $trips->links() }}
        </div>
    </div>
@endsection
