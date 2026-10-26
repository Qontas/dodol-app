<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * TUGAS 4c (BRIEF 23 Okt 2026) — hitungan "mika kena fee aplikasi" + "Fee Rp"
 * PER OWNER, BATCH (jumlah query KONSTAN berapa pun banyak owner). Fee aplikasi
 * = Rp yang owner partner bayar ke pemilik aplikasi per kemasan (mika) yang
 * diantar operatornya; Rp 0 untuk owner yang users.app_fee_per_pack-nya NULL
 * (termasuk Ismi sendiri).
 *
 * 🔴 KEPUTUSAN BISNIS (BRIEF 26 Okt 2026): titipan konsinyasi PERTAMA di kedai
 * baru SEKARANG IKUT kena fee — pengecualian lama ("titipan pertama tidak
 * dihitung") DIHAPUS SELURUHNYA. Berlaku SEJAK AWAL DATA (tidak ada tanggal
 * batas): fee dihitung LIVE dari deliveries (bukan snapshot bulanan), jadi
 * cukup ubah aturan di sini — tidak perlu backfill/migrasi apapun.
 *
 * ATURAN HITUNG (sudah dipetakan ke SEMUA delivery_type & jalur pembuatnya —
 * lihat laporan akhir TUGAS 4 untuk tabel lengkap), mika kena fee = SUM
 * deliveries.qty_delivered KECUALI:
 *   1) delivery_type = 'bs_redistribution' (Dodol Sisa) → tidak dihitung (daur
 *      ulang BS antar kios, bukan stok bawaan baru — cegah dobel hitung sama
 *      seperti basis komisi Rian, lihat poin di bawah).
 *   2) trip sentinel migrasi (operator akun sistem — Trip::isMigrationSentinel())
 *      → tidak dihitung (apapun delivery_type-nya).
 *   3) trip diarsip (soft delete, trips.deleted_at NOT NULL) → tidak dihitung
 *      (apapun delivery_type-nya).
 * Tidak ada pengecualian lain — delivery_type='consignment' maupun 'cash_sale'
 * (Titip Cash & Jual Cash Cepat/walk-in) DIHITUNG SAMA, termasuk titipan
 * pertama tiap kios.
 *
 * KONSEKUENSI LANGSUNG dari penghapusan aturan "titipan pertama": "mika kena
 * fee" di atas SEKARANG IDENTIK dengan basis komisi Rian (Trip::mika_komisi /
 * getTotalDropReal() — SUM qty_delivered KECUALI bs_redistribution, dipaksa 0
 * untuk trip sentinel, lihat KomisiDropBasisTest). Dibuktikan lewat
 * AppFeeCalculatorTest::test_mika_kena_fee_sama_dengan_basis_komisi_rian_per_trip().
 *
 * Fee Rp = SUM(qty_delivered_kena_fee × trips.app_fee_per_pack_snapshot) — BUKAN
 * mika_kena_fee × tarif OWNER SAAT INI, karena snapshot per-trip bisa beda-beda
 * kalau tarif owner pernah diubah (trip lama tetap pakai tarif lamanya — sama
 * persis pola komisi_per_mika_snapshot). Kolom "Tarif" di widget tetap tampilkan
 * tarif owner SAAT INI (users.app_fee_per_pack) murni sebagai referensi.
 */
class AppFeeCalculator
{
    /**
     * @return array<int, array<string, mixed>> keyed by owner_id
     */
    public static function summaryForOwners(): array
    {
        $owners = User::query()->where('role', 'owner')->get(['id', 'name', 'app_fee_per_pack']);

        $out = [];
        foreach ($owners as $owner) {
            $out[$owner->id] = [
                'owner_id' => $owner->id,
                'owner_name' => $owner->name,
                'rate' => $owner->getAppFeePerPackValue(),
                'mika_today' => 0,
                'mika_month' => 0,
                'mika_total' => 0,
                'fee_today' => 0.0,
                'fee_month' => 0.0,
                'fee_total' => 0.0,
            ];
        }

        if ($owners->isEmpty()) {
            return $out;
        }

        $today = today()->toDateString();
        $monthStart = today()->startOfMonth()->toDateString();
        $monthEnd = today()->endOfMonth()->toDateString();

        // Mika + fee per owner, 3 rentang (hari ini/bulan ini/total) sekaligus
        // lewat CASE WHEN — SATU query batch lintas SEMUA owner (tanpa subquery
        // "titipan pertama" lagi sejak BRIEF 26 Okt 2026 — lihat docblock kelas).
        $rows = DB::table('deliveries as d')
            ->join('trips as t', 't.id', '=', 'd.trip_id')
            ->join('users as op', 'op.id', '=', 't.operator_id')
            ->where('d.delivery_type', '<>', 'bs_redistribution')
            ->where('op.email', 'not like', User::SYSTEM_OPERATOR_EMAIL_PREFIX.'%')
            ->whereNull('t.deleted_at')
            ->groupBy('t.owner_id')
            ->selectRaw(
                't.owner_id as owner_id,'.
                'COALESCE(SUM(CASE WHEN t.trip_date = ? THEN d.qty_delivered ELSE 0 END), 0) as mika_today,'.
                'COALESCE(SUM(CASE WHEN t.trip_date BETWEEN ? AND ? THEN d.qty_delivered ELSE 0 END), 0) as mika_month,'.
                'COALESCE(SUM(d.qty_delivered), 0) as mika_total,'.
                'COALESCE(SUM(CASE WHEN t.trip_date = ? THEN d.qty_delivered * COALESCE(t.app_fee_per_pack_snapshot, 0) ELSE 0 END), 0) as fee_today,'.
                'COALESCE(SUM(CASE WHEN t.trip_date BETWEEN ? AND ? THEN d.qty_delivered * COALESCE(t.app_fee_per_pack_snapshot, 0) ELSE 0 END), 0) as fee_month,'.
                'COALESCE(SUM(d.qty_delivered * COALESCE(t.app_fee_per_pack_snapshot, 0)), 0) as fee_total',
                [$today, $monthStart, $monthEnd, $today, $monthStart, $monthEnd]
            )
            ->get()
            ->keyBy('owner_id');

        foreach ($rows as $ownerId => $row) {
            if (! isset($out[$ownerId])) {
                continue; // owner di luar daftar role='owner' (seharusnya tak terjadi).
            }

            $out[$ownerId]['mika_today'] = (int) $row->mika_today;
            $out[$ownerId]['mika_month'] = (int) $row->mika_month;
            $out[$ownerId]['mika_total'] = (int) $row->mika_total;
            $out[$ownerId]['fee_today'] = (float) $row->fee_today;
            $out[$ownerId]['fee_month'] = (float) $row->fee_month;
            $out[$ownerId]['fee_total'] = (float) $row->fee_total;
        }

        return $out;
    }

    /**
     * TUGAS 1 (BRIEF 23 Okt 2026, lanjutan) — versi PER TANGGAL dari
     * summaryForOwners(), untuk widget "Rincian Fee Aplikasi Harian".
     *
     * mika_kena_fee & fee: ATURAN HITUNG SAMA PERSIS summaryForOwners()
     * (lihat docblock kelas di atas — TIDAK ditulis ulang), cuma dengan
     * GROUP BY tambahan t.trip_date + dibatasi ke satu bulan.
     *
     * BRIEF 26 Okt 2026: dulu widget ini juga punya metrik "mika_diantar"
     * (SEMUA delivery trip nyata TERMASUK titipan pertama, sebelum aturan
     * "titipan pertama tidak dihitung" itu dihapus). Sejak aturan itu dihapus,
     * mika_diantar akan SELALU identik dengan mika_kena_fee (keduanya sekarang
     * sama-sama aturan 1-3 saja) — kolom duplikat dihapus, widget tampilkan
     * SATU kolom "Mika Kena Fee" saja (lihat AppFeeDailyWidget + blade-nya).
     *
     * @return array<int, array{date: string, owner_id: int, owner_name: string, mika_kena_fee: int, fee: float}>
     *         Terurut per tanggal lalu nama owner. HANYA tanggal yang ada
     *         aktivitas (tak ada baris nihil untuk tanggal kosong).
     */
    public static function summaryByOwnerAndDate(int $year, int $month): array
    {
        $monthStart = \Illuminate\Support\Carbon::create($year, $month, 1)->startOfMonth();
        $monthEnd = (clone $monthStart)->endOfMonth();

        $rows = DB::table('deliveries as d')
            ->join('trips as t', 't.id', '=', 'd.trip_id')
            ->join('users as op', 'op.id', '=', 't.operator_id')
            ->join('users as o', 'o.id', '=', 't.owner_id')
            ->where('d.delivery_type', '<>', 'bs_redistribution')
            ->where('op.email', 'not like', User::SYSTEM_OPERATOR_EMAIL_PREFIX.'%')
            ->whereNull('t.deleted_at')
            ->whereBetween('t.trip_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->groupBy('t.trip_date', 't.owner_id', 'o.name')
            ->selectRaw(
                't.trip_date as trip_date,'.
                't.owner_id as owner_id,'.
                'o.name as owner_name,'.
                'COALESCE(SUM(d.qty_delivered), 0) as mika_kena_fee,'.
                'COALESCE(SUM(d.qty_delivered * COALESCE(t.app_fee_per_pack_snapshot, 0)), 0) as fee'
            )
            ->orderBy('t.trip_date')
            ->orderBy('o.name')
            ->get();

        return $rows->map(fn ($row) => [
            'date' => (string) $row->trip_date,
            'owner_id' => (int) $row->owner_id,
            'owner_name' => $row->owner_name,
            'mika_kena_fee' => (int) $row->mika_kena_fee,
            'fee' => (float) $row->fee,
        ])->all();
    }
}
