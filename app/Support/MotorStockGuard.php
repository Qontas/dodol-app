<?php

namespace App\Support;

use App\Exceptions\InsufficientMotorStockException;
use App\Models\Trip;

/**
 * BRIEF 27 Okt 2026 "PENGAMAN STOK DI MOTOR" — satu jalur check-and-lock dipakai
 * SEMUA aksi yang mengeluarkan mika dari stok di motor (Tagih + Titip Ulang, Titip
 * Cash, Mulai Titipan, Jual Cash Cepat, Kios Baru konsinyasi dengan trip aktif,
 * Koreksi yang MENAMBAH qty). Dodol Sisa (bs_redistribution) TIDAK PERNAH lewat
 * sini — itu berasal dari kios, bukan dari stok motor (lihat Trip::getStokDiMotor()).
 *
 * WAJIB dipanggil sebagai statement PERTAMA di dalam closure DB::transaction() yang
 * benar-benar menulis delivery-nya (bukan sebelum transaction dibuka) — lockForUpdate()
 * di sini mengunci baris trip SELAMA transaction itu berjalan, jadi 2 request simultan
 * akan serial (request kedua menunggu lock request pertama lepas, lalu membaca stok yang
 * SUDAH ter-update) — inilah yang mencegah race condition qty melebihi stok.
 */
class MotorStockGuard
{
    /**
     * @throws InsufficientMotorStockException bila $qty > stok di motor saat ini.
     */
    public static function assertAvailable(Trip $trip, int $qty): Trip
    {
        /** @var Trip $locked */
        $locked = Trip::whereKey($trip->id)->lockForUpdate()->first();

        $stokDiMotor = $locked->getStokDiMotor();

        if ($qty > $stokDiMotor) {
            throw new InsufficientMotorStockException($stokDiMotor, $qty);
        }

        return $locked;
    }
}
