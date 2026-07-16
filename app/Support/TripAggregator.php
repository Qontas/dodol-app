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
     * @param  float  $hpp             HPP per mika owner (Trip::getHpp fallback 9500)
     * @param  float  $komisiPerMika   tarif komisi per mika drop (fallback 1000)
     * @return array<int, array<string, float|int>>
     */
    public static function for(array $tripIds, float $hpp, float $komisiPerMika): array
    {
        $tripIds = array_values(array_unique(array_map('intval', $tripIds)));
        if (empty($tripIds)) {
            return [];
        }

        // (1) Total drop (semua) + drop basis komisi (exclude BS daur-ulang). Identik
        //     deliveries->sum('qty_delivered') & getTotalDropReal (delivery_type <> BS).
        $deliveryAgg = Delivery::whereIn('trip_id', $tripIds)
            ->groupBy('trip_id')
            ->selectRaw('trip_id,
                COALESCE(SUM(qty_delivered), 0) as mika_diantar,
                COALESCE(SUM(CASE WHEN delivery_type <> ? THEN qty_delivered ELSE 0 END), 0) as drop_komisi',
                ['bs_redistribution'])
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

        // (3) Settlement per delivery (amount_paid + qty_sold), dijumlah per trip di PHP.
        $settlements = empty($allDeliveryIds)
            ? collect()
            : Settlement::whereIn('delivery_id', $allDeliveryIds)
                ->get(['delivery_id', 'amount_paid', 'qty_sold'])
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
        //     konsistensi (id yang masuk pun sudah trip non-arsip milik owner).
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
                COALESCE(SUM(nd.qty_delivered), 0) as mika_kios_baru')
            ->get()
            ->keyBy('trip_id');

        $out = [];
        foreach ($tripIds as $id) {
            $mikaDiantar = (int) ($deliveryAgg[$id]->mika_diantar ?? 0);
            $dropKomisi = (float) ($deliveryAgg[$id]->drop_komisi ?? 0);

            $omset = 0.0;
            $qtySold = 0.0;
            foreach ($deliveryIdsByTrip[$id] ?? [] as $deliveryId) {
                if ($s = $settlements->get($deliveryId)) {
                    $omset += (float) $s->amount_paid;
                    $qtySold += (float) $s->qty_sold;
                }
            }

            // Rumus IDENTIK accessor Trip (jangan diubah).
            $mikaTerjual = $qtySold / 15;
            $hppEstimasi = $mikaTerjual * $hpp;
            $untungKotor = $mikaTerjual * (12000 - $hpp);
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
            ];
        }

        return $out;
    }
}
