<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * TUGAS 4c (BRIEF 23 Okt 2026) — hitungan "mika kena fee aplikasi" + "Fee Rp"
 * PER OWNER, BATCH (jumlah query KONSTAN berapa pun banyak owner — lihat
 * AppFeeWidgetQueryCountTest). Fee aplikasi = Rp yang owner partner bayar ke
 * pemilik aplikasi per kemasan (mika) yang diantar operatornya; Rp 0 untuk
 * owner yang users.app_fee_per_pack-nya NULL (termasuk Ismi sendiri).
 *
 * ATURAN HITUNG (sudah dipetakan ke SEMUA delivery_type & jalur pembuatnya —
 * lihat laporan akhir TUGAS 4 untuk tabel lengkap), mika kena fee = SUM
 * deliveries.qty_delivered KECUALI:
 *   1) delivery_type = 'bs_redistribution' (Dodol Sisa) → tidak dihitung.
 *   2) trip sentinel migrasi (operator akun sistem — Trip::isMigrationSentinel())
 *      → tidak dihitung (apapun delivery_type-nya).
 *   3) trip diarsip (soft delete, trips.deleted_at NOT NULL) → tidak dihitung
 *      (apapun delivery_type-nya).
 *   4) TITIPAN KONSINYASI PERTAMA tiap kios (delivery_type='consignment', DI LUAR
 *      trip sentinel) → tidak dihitung; titipan ke-2 dst DIHITUNG. "Pertama"
 *      ditentukan dari SEMUA delivery konsinyasi non-sentinel milik kios itu
 *      (arsip atau bukan — keputusan desain: kalau delivery pertama yang tak
 *      dihitung itu sendiri belakangan diarsip, titipan ke-2 TETAP dianggap
 *      "ke-2", bukan tiba-tiba jadi "pertama" lagi). Kios lama bersaldo-awal
 *      (delivery pertamanya ADA di trip sentinel, bukan di sini) otomatis
 *      TIDAK punya pengecualian "pertama" lagi di jalur non-sentinel — titipan
 *      non-sentinel pertama mereka ikut DIHITUNG.
 *      🔴 PENTING: "pertama" di atas WAJIB dihitung dari MIN(id) MENTAH, yaitu
 *      TERMASUK delivery sentinel (bukan difilter sentinel dulu baru MIN(id)) —
 *      kios lama bersaldo-awal punya delivery pertamanya justru DI trip sentinel
 *      itu. Kalau sentinel difilter SEBELUM MIN(id), MIN(id) yang tersisa jadi
 *      delivery NON-sentinel pertama kios itu (mis. "tagih+titip ulang" 4 mika
 *      Bu Sri) → salah ditandai "pertama" → fee-nya 0, padahal harusnya DIHITUNG
 *      PENUH (persis kebalikan kalimat di atas). Dengan MIN(id) mentah, delivery
 *      sentinel kios lama itulah yang ketangkap sebagai "pertama" (toh sudah pasti
 *      tak dihitung juga lewat aturan 2), sehingga titipan non-sentinel pertama
 *      mereka bebas dari pengecualian ini.
 *   5) Penjualan tunai (delivery_type='cash_sale': Titip Cash & Jual Cash
 *      Cepat/walk-in) → dihitung semua (tak kena aturan 4, bukan consignment;
 *      tetap tunduk aturan 2/3 kalau hipotetis ada di trip sentinel/diarsip,
 *      walau pada praktiknya tak pernah terjadi — lihat tabel pemetaan).
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

        // (1) Titipan konsinyasi PERTAMA tiap kios — SATU query batch lintas SEMUA
        //     kios/owner (bukan N+1, bukan per-owner). MIN(d.id) MENTAH, TANPA
        //     filter sentinel/arsip dulu (lihat 🔴 di docblock kelas di atas) —
        //     kios lama bersaldo-awal punya delivery pertamanya justru DI trip
        //     sentinel, jadi sentinel WAJIB ikut dihitung di sini supaya delivery
        //     NON-sentinel pertama mereka tidak salah ditandai "pertama".
        $firstConsignmentDeliveryIds = DB::table('deliveries as d')
            ->where('d.delivery_type', 'consignment')
            ->groupBy('d.kiosk_id')
            ->selectRaw('MIN(d.id) as first_id')
            ->pluck('first_id')
            ->all();

        $today = today()->toDateString();
        $monthStart = today()->startOfMonth()->toDateString();
        $monthEnd = today()->endOfMonth()->toDateString();

        // (2) Mika + fee per owner, 3 rentang (hari ini/bulan ini/total) sekaligus
        //     lewat CASE WHEN — SATU query batch lintas SEMUA owner.
        $rows = DB::table('deliveries as d')
            ->join('trips as t', 't.id', '=', 'd.trip_id')
            ->join('users as op', 'op.id', '=', 't.operator_id')
            ->where('d.delivery_type', '<>', 'bs_redistribution')
            ->where('op.email', 'not like', User::SYSTEM_OPERATOR_EMAIL_PREFIX.'%')
            ->whereNull('t.deleted_at')
            ->when(
                ! empty($firstConsignmentDeliveryIds),
                fn ($q) => $q->whereNotIn('d.id', $firstConsignmentDeliveryIds)
            )
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
}
