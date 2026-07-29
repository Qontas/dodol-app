<?php

namespace App\Support;

use App\Models\Kiosk;
use Illuminate\Support\Facades\DB;

/**
 * AREA yang BENAR-BENAR DISENTUH sebuah trip — bukan sekadar `starting_cluster_id`.
 *
 * Kenapa ada (29 Juli 2026): sejak operator boleh menyeberang area DI TENGAH trip
 * (lihat ActiveTrip::switchArea), satu trip dengan `starting_cluster_id` = "Kota 1"
 * bisa berisi kunjungan di Pancing. Label "Kota 1" polos di Riwayat Trip, di detail
 * trip, dan denominator "5 dari 54" di dashboard owner jadi BOHONG.
 *
 * Dua query BATCH, konstan berapa pun jumlah trip — jangan panggil dalam loop
 * per-baris (pola yang sama dengan App\Support\TripAggregator).
 *
 * ⚠️ Ini MURNI soal PELABELAN & cakupan daftar. Komisi, omset, stok, untung TIDAK
 * disentuh sama sekali — lintas area cuma menentukan kios mana yang tampil dan
 * dikunjungi, bukan cara menghitung uang.
 */
class TripAreas
{
    /** Berapa nama area yang disebut sebelum diringkas jadi "+N lagi". */
    private const MAKS_NAMA = 2;

    /**
     * @param  array<int>  $tripIds
     * @return array<int, array{
     *     starting_id: int|null,
     *     starting_name: string|null,
     *     visited: array<int, string>,
     *     crossed: bool,
     *     label: string,
     *     relevant_cluster_ids: array<int>
     * }>
     */
    public static function for(array $tripIds): array
    {
        $tripIds = array_values(array_unique(array_map('intval', $tripIds)));

        if (empty($tripIds)) {
            return [];
        }

        // (1) Area AWAL tiap trip. withTrashed by design: trip terarsip tetap punya
        // label jujur di halaman Riwayat Trip (route-nya memang ->withTrashed()).
        $starts = DB::table('trips')
            ->leftJoin('clusters as sc', 'trips.starting_cluster_id', '=', 'sc.id')
            ->whereIn('trips.id', $tripIds)
            ->get(['trips.id', 'trips.starting_cluster_id', 'sc.name as starting_name'])
            ->keyBy('id');

        // (2) Area yang BENAR-BENAR dikunjungi (kunjungan aktif saja — yang dikoreksi
        // tidak dihitung, konsisten dengan KioskVisit::active()). Cluster sentinel
        // walk-in dikecualikan: penjualan walk-in bukan "menyeberang area".
        $visitRows = DB::table('kiosk_visits')
            ->join('kiosks', 'kiosk_visits.kiosk_id', '=', 'kiosks.id')
            ->join('clusters', 'kiosks.cluster_id', '=', 'clusters.id')
            ->whereIn('kiosk_visits.trip_id', $tripIds)
            ->whereNull('kiosk_visits.corrected_at')
            ->where('clusters.name', 'not like', Kiosk::WALKIN_CLUSTER_PREFIX.'%')
            ->distinct()
            ->get(['kiosk_visits.trip_id', 'clusters.id as cluster_id', 'clusters.name as cluster_name']);

        $visitedPerTrip = [];
        foreach ($visitRows as $row) {
            $visitedPerTrip[(int) $row->trip_id][(int) $row->cluster_id] = (string) $row->cluster_name;
        }

        $out = [];
        foreach ($tripIds as $tripId) {
            $start = $starts->get($tripId);
            $startingId = $start?->starting_cluster_id !== null ? (int) $start->starting_cluster_id : null;
            $startingName = $start?->starting_name;

            $visited = $visitedPerTrip[$tripId] ?? [];
            ksort($visited);

            $lain = $startingId === null
                ? []
                : array_diff_key($visited, [$startingId => true]);

            $crossed = $startingId !== null && ! empty($lain);

            $relevant = $startingId === null
                ? array_keys($visited)
                : array_values(array_unique(array_merge([$startingId], array_keys($visited))));

            $out[$tripId] = [
                'starting_id' => $startingId,
                'starting_name' => $startingName,
                'visited' => $visited,
                'crossed' => $crossed,
                'label' => self::label($startingName, $startingId, $visited, $lain),
                'relevant_cluster_ids' => $relevant,
            ];
        }

        return $out;
    }

    /** Versi satu-trip (detail trip). Tetap lewat batch supaya aturannya satu. */
    public static function forTrip(int $tripId): array
    {
        return self::for([$tripId])[$tripId] ?? [
            'starting_id' => null, 'starting_name' => null, 'visited' => [],
            'crossed' => false, 'label' => 'Semua Kios', 'relevant_cluster_ids' => [],
        ];
    }

    /**
     * Label yang tak boleh menyembunyikan penyeberangan.
     *   Trip Bebas                 → "Semua Kios"
     *   satu area, tak menyeberang → "Kota 1"
     *   menyeberang                → "Kota 1 + lintas area: Pancing"
     *
     * @param  array<int, string>  $visited
     * @param  array<int, string>  $lain
     */
    private static function label(?string $startingName, ?int $startingId, array $visited, array $lain): string
    {
        if ($startingId === null) {
            return 'Semua Kios';
        }

        $nama = $startingName ?? 'Area';

        if (empty($lain)) {
            return $nama;
        }

        $namaLain = array_values($lain);
        $tampil = array_slice($namaLain, 0, self::MAKS_NAMA);
        $sisa = count($namaLain) - count($tampil);

        return $nama.' + lintas area: '.implode(', ', $tampil).($sisa > 0 ? " +{$sisa} lagi" : '');
    }
}
