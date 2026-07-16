<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\KioskVisit;
use App\Models\Trip;
use App\Models\User;
use App\Support\TripAggregator;
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
            $owner->getHppPerMikaValue(),
            $owner->getKomisiPerMikaValue(),
        );

        // Operator milik owner ini untuk dropdown filter.
        $operators = User::where('role', 'operator')
            ->where('owner_id', $ownerId)
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('owner.trips.index', [
            'trips' => $trips,
            'aggregates' => $aggregates,
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
    private function aggregatesFor(array $tripIds, float $hpp, float $komisiPerMika): array
    {
        $out = [];
        foreach (TripAggregator::for($tripIds, $hpp, $komisiPerMika) as $id => $a) {
            $out[$id] = [
                'kios' => $a['active_visits'],
                'mika_diantar' => $a['mika_diantar'],
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
        $trip->loadMissing([
            'operator',
            'startingCluster',
            'visits' => fn ($q) => $q->active()->orderBy('visited_at'),
            'visits.kiosk:id,name',
            'visits.newDelivery:id,qty_delivered',
            'visits.settledDelivery:id',
            'visits.settledDelivery.settlement:id,delivery_id,qty_sold,qty_returned_expired,amount_due,amount_paid',
        ]);

        $mikaDibawa = (int) ($trip->qty_carried_total ?? 0);
        $mikaDrop = (int) $trip->deliveries()->sum('qty_delivered');

        $visitRows = $trip->visits->map(function (KioskVisit $v) {
            $settlement = $v->settledDelivery?->settlement;

            return [
                'kiosk' => $v->kiosk?->name ?? '—',
                'action' => TripExportController::visitActionLabel($v->visit_action),
                'time' => $v->visited_at?->format('d M Y H:i') ?? '—',
                'mika_titip' => (int) ($v->newDelivery?->qty_delivered ?? 0),
                'bs_biji' => (int) ($settlement?->qty_returned_expired ?? 0),
                'uang' => (int) ($settlement?->amount_paid ?? 0),
            ];
        });

        return view('owner.trips.show', [
            'trip' => $trip,
            'summary' => [
                'mika_dibawa' => $mikaDibawa,
                'mika_drop' => $mikaDrop,
                'mika_sisa' => $mikaDibawa - $mikaDrop,
            ],
            'visitRows' => $visitRows,
        ]);
    }
}
