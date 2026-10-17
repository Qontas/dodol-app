<?php

namespace App\Support;

use App\Models\Delivery;
use App\Models\KioskVisit;
use App\Models\Settlement;
use Illuminate\Support\Facades\DB;

/**
 * Agregat finansial + hitungan kios untuk BANYAK trip sekaligus (BATCH, jumlah query
 * KONSTAN berapa pun banyak trip). Meniru PERSIS accessor model Trip:
 *   omset_val, mika_terjual, mika_komisi (getTotalDropReal), komisi_rian, hpp_estimasi,
 *   untung_kotor, untung_bersih_owner, kios_baru_count, mika_kios_baru, kios_lama_count.
 *
 * Ini MURNI pengurang query (hilangkan N+1 accessor per-baris) — rumus & konstanta TIDAK
 * diubah, angka HARUS identik dengan accessor. Satu pola dipakai dashboard owner, laporan
 * bulanan, dan Riwayat Trip (bukan tiga pendekatan). Accessor per-baris tetap ada untuk
 * pemakaian single-trip (mis. detail/export); JANGAN dipakai dalam loop banyak-trip.
 */
class TripAggregator
{
    /**
     * @param  array<int>  $tripIds
     * @param  float  $komisiPerMika   tarif komisi per mika drop (fallback 1000)
     * @return array<int, array<string, float|int>>
     */
    public static function for(array $tripIds, float $komisiPerMika): array
    {
        $tripIds = array_values(array_unique(array_map('intval', $tripIds)));
        if (empty($tripIds)) {
            return [];
        }

        // (1) Total drop (semua) + drop basis komisi (exclude BS daur-ulang). Identik
        //     deliveries->sum('qty_delivered') & getTotalDropReal (delivery_type <> BS).
        //     Kolom biji_* = tampilan tambahan "X mika (Y biji)" (Trip::biji_diantar/
        //     biji_komisi) — units_per_pack_snapshot sudah di tabel deliveries sendiri,
        //     tak perlu join tambahan.
        $deliveryAgg = Delivery::whereIn('trip_id', $tripIds)
            ->groupBy('trip_id')
            ->selectRaw('trip_id,
                COALESCE(SUM(qty_delivered), 0) as mika_diantar,
                COALESCE(SUM(CASE WHEN delivery_type <> ? THEN qty_delivered ELSE 0 END), 0) as drop_komisi,
                COALESCE(SUM(qty_delivered * COALESCE(units_per_pack_snapshot, 15)), 0) as biji_diantar,
                COALESCE(SUM(CASE WHEN delivery_type <> ? THEN qty_delivered * COALESCE(units_per_pack_snapshot, 15) ELSE 0 END), 0) as biji_komisi',
                ['bs_redistribution', 'bs_redistribution'])
            ->get()
            ->keyBy('trip_id');

        // (2) settled_delivery_id per trip dari kunjungan AKTIF (basis omset & mika_terjual,
        //     identik omset_val/mika_terjual). unique per trip → tak double-count (mirror
        //     pluck()->whereIn() pada accessor).
        $settledRows = KioskVisit::active()
            ->whereIn('trip_id', $tripIds)
            ->whereNotNull('settled_delivery_id')
            ->get(['trip_id', 'settled_delivery_id']);

        $deliveryIdsByTrip = [];
        foreach ($settledRows as $row) {
            $deliveryIdsByTrip[$row->trip_id][$row->settled_delivery_id] = $row->settled_delivery_id;
        }
        $allDeliveryIds = collect($deliveryIdsByTrip)->flatMap(fn ($ids) => array_values($ids))->unique()->all();

        // (3) Settlement per delivery (amount_paid + qty_sold) JOIN deliveries untuk ambil
        //     snapshot isi/harga/HPP milik delivery itu sendiri — bukan lagi satu HPP flat
        //     per pemanggil (owner bisa punya >1 varian/isi). Kolom qualified "deliveries.x"
        //     muncul sebagai atribut TANPA prefix pada baris hasil, maka keyBy('delivery_id')
        //     (bukan 'settlements.delivery_id').
        $settlements = empty($allDeliveryIds)
            ? collect()
            : Settlement::whereIn('settlements.delivery_id', $allDeliveryIds)
                ->join('deliveries', 'deliveries.id', '=', 'settlements.delivery_id')
                ->get([
                    'settlements.delivery_id',
                    'settlements.amount_paid',
                    'settlements.qty_sold',
                    'deliveries.unit_price',
                    'deliveries.cost_snapshot',
                    'deliveries.units_per_pack_snapshot',
                ])
                ->keyBy('delivery_id');

        // (4) Jumlah kunjungan AKTIF per trip (basis kios_lama_count).
        $activeVisits = KioskVisit::active()
            ->whereIn('trip_id', $tripIds)
            ->groupBy('trip_id')
            ->selectRaw('trip_id, COUNT(*) as c')
            ->pluck('c', 'trip_id');

        // (5) Kios BARU (drop_only, first_titip_date = trip_date) → jumlah & mika titipnya.
        //     Identik getKiosBaruCountAttribute/getMikaKiosBaruAttribute. Join manual pada
        //     `trips` tak kena SoftDeletes global scope → whereNull(deleted_at) demi
        //     konsistensi (id yang masuk pun sudah trip non-arsip milik owner). Kolom
        //     biji_kios_baru = tampilan tambahan, identik Trip::biji_kios_baru.
        $kiosBaruRows = DB::table('kiosk_visits as kv')
            ->join('trips as t', 't.id', '=', 'kv.trip_id')
            ->join('kiosks as k', 'k.id', '=', 'kv.kiosk_id')
            ->leftJoin('deliveries as nd', 'nd.id', '=', 'kv.new_delivery_id')
            ->whereIn('kv.trip_id', $tripIds)
            ->whereNull('kv.corrected_at')
            ->whereNull('t.deleted_at')
            ->where('kv.visit_action', 'drop_only')
            ->whereRaw('DATE(k.first_titip_date) = t.trip_date')
            ->groupBy('kv.trip_id')
            ->selectRaw('kv.trip_id as trip_id,
                COUNT(*) as kios_baru,
                COALESCE(SUM(nd.qty_delivered), 0) as mika_kios_baru,
                COALESCE(SUM(nd.qty_delivered * COALESCE(nd.units_per_pack_snapshot, 15)), 0) as biji_kios_baru')
            ->get()
            ->keyBy('trip_id');

        // (6) Fallback HPP untuk baris lolos cost_snapshot NULL (jalur delivery lama
        //     sebelum snapshot ada) — PER OWNER trip ybs, identik Trip::getHpp()/
        //     salesSnapshotTotals() ("IDENTIK, jangan menyimpang"). Dulu hardcode 9500.0
        //     flat di sini, beda jalur dari accessor Trip yang baca owner->hpp_per_mika —
        //     bisa bikin dashboard (batch) vs detail trip (accessor) beda angka kalau HPP
        //     owner pernah diubah dari 9500. 1 query batch join trips→users, bukan N+1;
        //     aman untuk batch lintas-owner sekalipun (walau 3 caller saat ini per-owner).
        $fallbackHppByTrip = DB::table('trips')
            ->join('users', 'users.id', '=', 'trips.owner_id')
            ->whereIn('trips.id', $tripIds)
            ->pluck('users.hpp_per_mika', 'trips.id');

        $out = [];
        foreach ($tripIds as $id) {
            $mikaDiantar = (int) ($deliveryAgg[$id]->mika_diantar ?? 0);
            $dropKomisi = (float) ($deliveryAgg[$id]->drop_komisi ?? 0);
            $fallbackHpp = (float) ($fallbackHppByTrip[$id] ?? 9500);

            // Akumulasi PER-BARIS delivery snapshot (units_per_pack_snapshot/unit_price/
            // cost_snapshot) — bukan lagi qty_sold-total/15 dikali HPP-flat satu pemanggil,
            // supaya benar untuk trip yang menyentuh >1 isi/harga/HPP sekaligus. Identik
            // Trip::salesSnapshotTotals() (jangan menyimpang rumusnya).
            $omset = 0.0;
            $mikaTerjual = 0.0;
            $hppEstimasi = 0.0;
            $untungKotor = 0.0;
            // biji_terjual = qty_sold mentah (satuan asli settlements) — tampilan tambahan,
            // identik Trip::biji_terjual, TIDAK dipakai rumus uang.
            $bijiTerjual = 0;
            foreach ($deliveryIdsByTrip[$id] ?? [] as $deliveryId) {
                if (! ($s = $settlements->get($deliveryId))) {
                    continue;
                }

                $units = max(1, (int) ($s->units_per_pack_snapshot ?? 15));
                $mikaRow = ((float) $s->qty_sold) / $units;
                $cost = $s->cost_snapshot !== null ? (float) $s->cost_snapshot : $fallbackHpp;
                $price = (float) $s->unit_price;

                $omset += (float) $s->amount_paid;
                $mikaTerjual += $mikaRow;
                $hppEstimasi += $mikaRow * $cost;
                $untungKotor += $mikaRow * ($price - $cost);
                $bijiTerjual += (int) $s->qty_sold;
            }

            $komisi = $dropKomisi * $komisiPerMika;

            $activeCount = (int) ($activeVisits[$id] ?? 0);
            $kiosBaru = (int) ($kiosBaruRows[$id]->kios_baru ?? 0);

            $out[$id] = [
                'omset' => $omset,
                'mika_terjual' => $mikaTerjual,
                'mika_diantar' => $mikaDiantar,
                'mika_komisi' => $dropKomisi,
                'hpp_estimasi' => $hppEstimasi,
                'untung_kotor' => $untungKotor,
                'komisi' => $komisi,
                'untung_bersih' => $untungKotor - $komisi,
                'active_visits' => $activeCount,
                'kios_baru_count' => $kiosBaru,
                'mika_kios_baru' => (float) ($kiosBaruRows[$id]->mika_kios_baru ?? 0),
                'kios_lama_count' => max(0, $activeCount - $kiosBaru),
                // Tampilan tambahan "X mika (Y biji)" — lihat Trip::biji_terjual/
                // biji_diantar/biji_komisi/biji_kios_baru (sumber angka identik).
                'biji_terjual' => $bijiTerjual,
                'biji_diantar' => (int) ($deliveryAgg[$id]->biji_diantar ?? 0),
                'biji_komisi' => (int) ($deliveryAgg[$id]->biji_komisi ?? 0),
                'biji_kios_baru' => (int) ($kiosBaruRows[$id]->biji_kios_baru ?? 0),
            ];
        }

        return $out;
    }
}
