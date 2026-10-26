<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Trip;
use App\Models\User;
use App\Support\TripAggregator;
use App\Support\TripAreas;
use App\Support\VisitRowBuilder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TripHistoryController extends Controller
{
    /**
     * Riwayat SEMUA trip selesai milik owner (bukan cuma 5 di dashboard / per-bulan di
     * laporan), paginated + filter operator/tanggal/status. Angka finansial per baris
     * dihitung via AGREGAT BATCH (bukan accessor per-baris) supaya jumlah query KONSTAN
     * berapa pun baris di halaman — bukan N+1. Lihat aggregatesFor().
     */
    public function index(Request $request): View
    {
        $ownerId = (int) auth()->id();
        $status = $request->get('status', 'aktif'); // aktif | diarsip | semua
        $operatorId = $request->get('operator_id'); // '' = semua
        $from = $request->get('from');              // Y-m-d
        $to = $request->get('to');

        $query = Trip::query()
            ->where('owner_id', $ownerId)
            ->whereNotNull('ended_at')
            // Trip sentinel migrasi (saldo awal kedai lama) BUKAN perjalanan nyata —
            // dikecualikan dari Riwayat Trip (lihat Trip::scopeRealOperational()).
            ->realOperational()
            ->with('operator');

        // Status arsip (SoftDeletes). aktif = default (global scope buang trashed).
        if ($status === 'diarsip') {
            $query->onlyTrashed();
        } elseif ($status === 'semua') {
            $query->withTrashed();
        }

        if ($operatorId) {
            $query->where('operator_id', (int) $operatorId);
        }

        if ($from) {
            $query->whereDate('trip_date', '>=', $from);
        }
        if ($to) {
            $query->whereDate('trip_date', '<=', $to);
        }

        /** @var LengthAwarePaginator $trips */
        $trips = $query
            ->orderByDesc('ended_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $owner = auth()->user();
        $aggregates = $this->aggregatesFor(
            collect($trips->items())->pluck('id')->all(),
            $owner->getKomisiPerMikaValue(),
        );

        // Operator milik owner ini untuk dropdown filter.
        $operators = User::where('role', 'operator')
            ->where('owner_id', $ownerId)
            ->orderBy('name')
            ->get(['id', 'name']);

        // Label AREA yang jujur per baris. Sejak operator boleh menyeberang area di
        // tengah trip, "Kota 1" polos bisa menyembunyikan kunjungan di Pancing.
        // 2 query BATCH — bukan per-baris (pola sama dengan aggregatesFor()).
        $areas = TripAreas::for(collect($trips->items())->pluck('id')->all());

        return view('owner.trips.index', [
            'trips' => $trips,
            'aggregates' => $aggregates,
            'areas' => $areas,
            'operators' => $operators,
            'filters' => [
                'status' => $status,
                'operator_id' => $operatorId,
                'from' => $from,
                'to' => $to,
            ],
        ]);
    }

    /**
     * Agregat finansial untuk SEMUA trip di halaman sekaligus (batch), dikunci per
     * trip_id — jumlah query KONSTAN, bukan N+1. Delegasi ke TripAggregator (satu pola
     * dipakai dashboard + laporan bulanan + halaman ini, bukan tiga). Di sini hanya
     * memetakan ke kolom yang dipakai tabel Riwayat Trip. Angka identik accessor Trip.
     *
     * @param  array<int>  $tripIds
     * @return array<int, array<string, float|int>>
     */
    private function aggregatesFor(array $tripIds, float $komisiPerMika): array
    {
        $out = [];
        foreach (TripAggregator::for($tripIds, $komisiPerMika) as $id => $a) {
            $out[$id] = [
                'kios' => $a['active_visits'],
                'mika_diantar' => $a['mika_diantar'],
                'biji_diantar' => $a['biji_diantar'],
                'omset' => $a['omset'],
                'komisi' => $a['komisi'],
                'untung_bersih' => $a['untung_bersih'],
            ];
        }

        return $out;
    }

    /**
     * Detail satu trip: ringkasan finansial + daftar tiap kunjungan kios. Bisa untuk trip
     * terarsip juga (owner cek sebelum pulihkan) — route pakai withTrashed. Guard tenant:
     * hanya owner pemilik / super admin.
     */
    public function show(Trip $trip): View
    {
        abort_if(
            $trip->owner_id !== auth()->id() && ! auth()->user()->isSuperAdmin(),
            403
        );

        // Eager-load untuk hindari N+1 saat merender daftar kunjungan (satu trip).
        // BRIEF 26 Okt 2026 item 2 — list eager-load dipindah ke VisitRowBuilder::eagerLoad()
        // (dipakai juga TripExportController::buildTripData()), 'operator'/'startingCluster'
        // tetap ditambah di sini (kebutuhan halaman web, bukan baris kunjungan).
        $trip->loadMissing(array_merge(
            ['operator', 'startingCluster'],
            VisitRowBuilder::eagerLoad(),
        ));

        $mikaDibawa = (int) ($trip->qty_carried_total ?? 0);
        $mikaDrop = (int) $trip->deliveries()->sum('qty_delivered');

        $visitRows = VisitRowBuilder::forTrip($trip);

        return view('owner.trips.show', [
            'trip' => $trip,
            'summary' => [
                'mika_dibawa' => $mikaDibawa,
                'mika_drop' => $mikaDrop,
                'mika_sisa' => $mikaDibawa - $mikaDrop,
            ],
            'visitRows' => $visitRows,
            // Label area jujur — ikut menyebut area yang diseberangi, bukan cuma area awal.
            'area' => TripAreas::forTrip($trip->id),
        ]);
    }
}
