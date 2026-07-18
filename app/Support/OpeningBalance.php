<?php

namespace App\Support;

use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Saldo awal" titipan untuk KIOS LAMA (migrasi) — kios yang di dunia nyata SUDAH
 * punya titipan berjalan sebelum masuk sistem (mis. 9 kios owner yang baru diinput).
 *
 * KEPUTUSAN DESAIN (owner 11 Juli 2026): TIDAK ada kolom flag "kios lama vs baru".
 * "Punya titipan aktif" murni DITURUNKAN dari data = ada Delivery konsinyasi PENDING
 * (doesntHave('settlement')) — persis yang dipakai ActiveTrip::openVisitModal untuk
 * $pendingDelivery. Kios lama = kios yang punya saldo awal (delivery pending) ini →
 * operator langsung bisa "Tagih + Titip Ulang" di kunjungan pertama. Kios BARU (dibuat
 * operator di lapangan) tidak punya delivery → tampil sebagai belum-ada-titipan.
 *
 * Sejalan dengan Console command `kios:saldo-awal` (SeedKioskSaldoAwal) untuk migrasi
 * massal dari spreadsheet; helper ini dipakai saat owner input satu kios via Filament.
 */
class OpeningBalance
{
    /**
     * Buat 1 delivery konsinyasi PENDING (tanpa settlement) sebagai titipan berjalan.
     * Idempotensi ringan: kalau kios sudah punya delivery pending, TIDAK menumpuk.
     * Return null bila qty < 1 atau tak ada varian aktif owner.
     */
    public static function create(Kiosk $kiosk, int $qtyMika): ?Delivery
    {
        if ($qtyMika < 1) {
            return null;
        }

        // Jangan gandakan: kios yang sudah punya titipan pending dibiarkan apa adanya.
        if (Delivery::where('kiosk_id', $kiosk->id)->doesntHave('settlement')->exists()) {
            return null;
        }

        $ownerId = $kiosk->cluster?->owner_id;

        return DB::transaction(function () use ($kiosk, $qtyMika, $ownerId) {
            $trip = static::migrationTripFor($ownerId);

            // Kios lama: first_titip_date di masa lampau (sebelum sistem) supaya tidak
            // terhitung "kios baru" untuk komisi. Hanya set kalau belum diisi owner.
            if ($kiosk->first_titip_date === null) {
                $kiosk->forceFill(['first_titip_date' => today()->subDay()->toDateString()])->save();
            }

            // SATU jalur delivery: sama dgn drop operator, hanya trip-nya = trip migrasi.
            return ConsignmentDrop::record(
                $kiosk,
                $trip,
                $qtyMika,
                'Saldo awal (kios lama) — titipan berjalan sebelum masuk sistem.',
            );
        });
    }

    /**
     * Tanggal SENTINEL trip migrasi — sengaja di masa lampau jauh (tahun 2000) supaya
     * TIDAK PERNAH bentrok dengan trip operasional owner. Trip operasional dikunci unik
     * per (owner_id, trip_date, trip_number_of_day); dulu trip migrasi memakai
     * today()->subDay() + number 1 → bentrok dengan trip #1 owner kemarin →
     * UniqueConstraintViolation → 500 "Set Saldo Awal" tiap kali owner sudah jalan trip
     * kemarin (hampir selalu di produksi). Tanggal sentinel ini bukan trip nyata, jadi
     * aman dari bentrok dan tak mengotori laporan trip harian.
     */
    private const MIGRATION_TRIP_DATE = '2000-01-01';

    /**
     * Trip migrasi (langsung ended) per owner — bukan trip operasional, tidak lewat
     * flow end-trip sehingga tidak menghasilkan komisi. Dipakai bersama semua saldo
     * awal owner ini (firstOrCreate → satu trip migrasi per owner).
     */
    private static function migrationTripFor(?int $ownerId): Trip
    {
        $operator = User::firstOrCreate(
            ['email' => "operator.migrasi.owner{$ownerId}@cemilanqontas.id"],
            [
                'name' => 'Operator Migrasi',
                'password' => bcrypt(Str::random(32)),
                'role' => 'operator',
                'owner_id' => $ownerId,
                'is_active' => false,
            ],
        );

        return Trip::firstOrCreate(
            [
                'owner_id' => $ownerId,
                'operator_id' => $operator->id,
                'ended_reason' => 'Migrasi saldo awal (kios lama)',
            ],
            [
                'trip_date' => self::MIGRATION_TRIP_DATE,
                'trip_number_of_day' => 1,
                'started_at' => self::MIGRATION_TRIP_DATE.' 00:00:00',
                'ended_at' => self::MIGRATION_TRIP_DATE.' 00:00:00',
                'qty_carried_total' => 0,
                'notes' => 'Trip migrasi saldo awal — bukan trip operasional.',
            ],
        );
    }
}
