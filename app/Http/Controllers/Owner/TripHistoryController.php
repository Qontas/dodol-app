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
        $owner = auth()->user();
        $status = $request->get('status', 'aktif'); // aktif | diarsip | semua
        $operatorId = $request->get('operator_id'); // '' = semua
        $from = $request->get('from');              // Y-m-d
        $to = $request->get('to');

        // Builder dasar dipakai BERULANG (query filter + 3 varian hitungan status) —
        // closure supaya tiap pemanggilan dapat instance Builder baru (bukan kloning
        // state query sebelumnya), tetap SATU tempat definisi filter operator/tanggal.
        $baseQuery = function () use ($ownerId, $operatorId, $from, $to) {
            $q = Trip::query()
                ->where('owner_id', $ownerId)
                ->whereNotNull('ended_at')
                // Trip sentinel migrasi (saldo awal kedai lama) BUKAN perjalanan nyata —
                // dikecualikan dari Riwayat Trip (lihat Trip::scopeRealOperational()).
                ->realOperational();

            if ($operatorId) {
                $q->where('operator_id', (int) $operatorId);
            }
            if ($from) {
                $q->whereDate('trip_date', '>=', $from);
            }
            if ($to) {
                $q->whereDate('trip_date', '<=', $to);
            }

            return $q;
        };

        // BRIEF 27 Okt 2026 "KEJELASAN ARSIP" — hitungan Aktif/Diarsip/Semua di dropdown
        // status, MENGHORMATI filter operator/tanggal yang aktif tapi TERLEPAS dari
        // pilihan status itu sendiri (supaya owner tahu berapa trip di tiap status
        // sebelum pindah tab). 2 query count murah, bukan N+1.
        $aktifCount = $baseQuery()->count();
        $diarsipCount = $baseQuery()->onlyTrashed()->count();
        $statusCounts = [
            'aktif' => $aktifCount,
            'diarsip' => $diarsipCount,
            'semua' => $aktifCount + $diarsipCount,
        ];

        $query = $baseQuery()->with('operator');

        // Status arsip (SoftDeletes). aktif = default (global scope buang trashed).
        if ($status === 'diarsip') {
            $query->onlyTrashed();
        } elseif ($status === 'semua') {
            $query->withTrashed();
        }

        /** @var LengthAwarePaginator $trips */
        $trips = $query
            ->orderByDesc('ended_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

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

        // BRIEF 27 Okt 2026 "TRIP BERJALAN" — trip yang BELUM diakhiri (ended_at null)
        // tak pernah muncul di tabel di atas (whereNotNull('ended_at')), padahal
        // omsetnya sudah masuk hitungan admin "Total Trip Hari Ini" (lintas owner,
        // termasuk yang masih berjalan — lihat SuperAdminStatsOverview). Ditampilkan
        // di SINI terpisah, bukan digabung ke tabel trip selesai (beda semantik: masih
        // bisa berubah, tak boleh diarsip dari sini).
        $runningTrips = Trip::where('owner_id', $ownerId)
            ->whereNull('ended_at')
            ->realOperational()
            ->with('operator')
            ->orderByDesc('started_at')
            ->get();

        $runningAgg = $this->aggregatesFor(
            $runningTrips->pluck('id')->all(),
            $owner->getKomisiPerMikaValue(),
        );

        return view('owner.trips.index', [
            'trips' => $trips,
            'aggregates' => $aggregates,
            'areas' => $areas,
            'operators' => $operators,
            'statusCounts' => $statusCounts,
            'runningTrips' => $runningTrips,
            'runningAgg' => $runningAgg,
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
                // BRIEF 27 Okt 2026 "KOLOM KIOS" — $a['kios'] (unik, lihat TripAggregator §8b),
                // BUKAN lagi $a['active_visits'] (cuma COUNT kiosk_visits — undercount kios
                // yang titip lewat jalur "Kios Baru"/OpeningBalance tanpa kunjungan tercatat).
                'kios' => $a['kios'],
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
     * Daftar key filter yang diteruskan apa adanya antar halaman Riwayat Trip (query
     * string index ⇄ link "Kembali" di detail). SATU tempat definisi supaya index()
     * dan show() tak pernah diam-diam beda daftar kalau salah satu diubah.
     */
    private const FILTER_KEYS = ['status', 'operator_id', 'from', 'to'];

    /**
     * Detail satu trip: ringkasan finansial + daftar tiap kunjungan kios. Bisa untuk trip
     * terarsip juga (owner cek sebelum pulihkan) — route pakai withTrashed. Guard tenant:
     * hanya owner pemilik / super admin.
     */
    public function show(Request $request, Trip $trip): View
    {
        abort_if(
            $trip->owner_id !== auth()->id() && ! auth()->user()->isSuperAdmin(),
            403
        );

        // BRIEF 27 Okt 2026 "KEMBALI MENAMPILKAN sw.js" — link "Kembali ke Riwayat Trip"
        // HARUS ke route('owner.trips.index') + filter yang lagi aktif, BUKAN
        // url()->previous() (rawan ketiban Referer/_previous.url dari request
        // non-halaman seperti /csrf-token, /kiosks/{id}/photo — lihat
        // App\Http\Middleware\StartSession). Filter diwhitelist via FILTER_KEYS, tak
        // ada input bebas yang dipakai sebagai URL.
        $backUrl = route('owner.trips.index', array_filter(
            $request->only(self::FILTER_KEYS),
            fn ($v) => $v !== null && $v !== ''
        ));

        // Eager-load untuk hindari N+1 saat merender daftar kunjungan (satu trip).
        // BRIEF 26 Okt 2026 item 2 — list eager-load dipindah ke VisitRowBuilder::eagerLoad()
        // (dipakai juga TripExportController::buildTripData()), 'operator'/'startingCluster'
        // tetap ditambah di sini (kebutuhan halaman web, bukan baris kunjungan).
        $trip->loadMissing(array_merge(
            ['operator', 'startingCluster', 'stockAdditions.addedBy'],
            VisitRowBuilder::eagerLoad(),
        ));

        $mikaDibawa = (int) ($trip->qty_carried_total ?? 0);
        $mikaDrop = (int) $trip->deliveries()->sum('qty_delivered');

        $visitRows = VisitRowBuilder::forTrip($trip);

        // BRIEF 27 Okt 2026 "KOLOM KIOS" — "Kios Unik" (TripAggregator §8b, SATU SUMBER
        // dgn Riwayat/Laporan Bulanan/Laporan Periode/dashboard) ditampilkan terpisah dari
        // "Kunjungan Kios (N)" di bawah — N itu jumlah BARIS kunjungan/event (VisitRowBuilder,
        // SENGAJA tak diubah, beda pertanyaan), bisa > kios unik kalau 1 kios dikunjungi 2×.
        $kiosUnik = (int) (TripAggregator::for([$trip->id], $trip->owner?->getKomisiPerMikaValue() ?? 1000.0)[$trip->id]['kios'] ?? 0);

        return view('owner.trips.show', [
            'trip' => $trip,
            'summary' => [
                'mika_dibawa' => $mikaDibawa,
                'mika_dibawa_display' => $trip->mika_dibawa_display,
                'mika_tambahan' => (int) $trip->qty_added_total,
                'mika_drop' => $mikaDrop,
                // BRIEF 27 Okt 2026 "PENGAMAN STOK DI MOTOR" — Trip::getStokDiMotor()
                // (SATU sumber kebenaran), BUKAN lagi mikaDibawa - mikaDrop mentah.
                // Lihat catatan sama di TripExportController::buildTripData().
                'mika_sisa' => $trip->getStokDiMotor(),
            ],
            'visitRows' => $visitRows,
            // Riwayat "➕ Tambah Stok di Motor" (waktu, jumlah, oleh siapa).
            'stockAdditions' => $trip->stockAdditions,
            // Label area jujur — ikut menyebut area yang diseberangi, bukan cuma area awal.
            'area' => TripAreas::forTrip($trip->id),
            // Tujuan tetap "← Kembali ke Riwayat Trip" + hidden field form arsip/pulihkan
            // (lihat TripDeleteController) — SELALU route eksplisit, bukan previous()/back().
            'backUrl' => $backUrl,
            'filters' => $request->only(self::FILTER_KEYS),
            'kiosUnik' => $kiosUnik,
        ]);
    }
}
