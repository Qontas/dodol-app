<?php

namespace App\Support;

use App\Models\Commission;
use App\Models\Trip;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * BRIEF 27 Okt 2026 "TRIP LUPA DIAKHIRI" item 1 — SATU JALUR AKHIRI TRIP. Dulu
 * semua efek samping (ended_at, ended_reason, baris commissions) ditulis inline
 * di ActiveTrip::confirmEndTrip() (tombol "Akhiri Trip"). Sekarang dipindah ke
 * sini supaya penutupan OTOMATIS (trip lupa diakhiri ganti hari, lihat
 * TripAutoCloser) memakai jalur IDENTIK — tidak ada logika ganda yang bisa
 * berbeda hasilnya.
 *
 * Logika komisi di bawah SENGAJA disalin apa adanya dari confirmEndTrip() lama
 * (tidak diubah rumusnya) — lihat KomisiRianTest/ActiveTripTest untuk kontrak
 * nilai yang sudah terkunci.
 *
 * Idempotent: trip yang ended_at-nya sudah terisi TIDAK diproses ulang (return
 * false), supaya trigger auto-close yang terpanggil berkali-kali (tiap halaman
 * dibuka) aman dipanggil berulang tanpa membuat baris commissions dobel.
 */
class TripCloser
{
    /**
     * @param  string  $reason  'stock_habis'|'target_done'|'sakit'|'urgent_personal'|'other'
     *                          (tombol manual, lihat ActiveTrip::VALID_END_REASONS) ATAU
     *                          'auto_closed' (penutupan otomatis ganti hari).
     * @param  Carbon|null  $endedAt  Waktu ended_at yang ditulis. Default now() (tombol
     *                                manual). Penutupan otomatis mengirim waktu aktivitas
     *                                terakhir trip itu (lihat TripAutoCloser).
     */
    public static function close(Trip $trip, string $reason, ?Carbon $endedAt = null): bool
    {
        if ($trip->ended_at !== null) {
            return false;
        }

        $endedAt ??= now();

        DB::transaction(function () use ($trip, $reason, $endedAt) {
            $trip->update([
                'ended_at' => $endedAt,
                'ended_reason' => $reason,
            ]);

            // Save commission record to the database using the new formula
            // (DISALIN APA ADANYA dari ActiveTrip::confirmEndTrip() lama — jangan
            // ubah rumus di sini, lihat docblock kelas).
            $omset = $trip->omset_val;
            $komisi = $trip->komisi_rian;

            $cashCollectedReported = $omset;
            $marginRateAssumed = 0;

            if ($omset > 0) {
                $marginRateAssumed = $komisi / ($omset * 0.2000);
            }

            // Fallback if omset is 0 or margin rate would overflow decimal(5,4)
            if ($omset == 0 || $marginRateAssumed > 9.0) {
                $cashCollectedReported = $komisi / 0.2000;
                $marginRateAssumed = 1.0000;
            }

            // BRIEF 2 (21 Okt 2026): teks catatan HARUS menyebut tarif yang BENAR-BENAR
            // dipakai menghitung $komisi di atas (komisi_rian, baca snapshot trip ini),
            // bukan tarif owner SAAT INI.
            $komisiPerMika = $trip->komisi_per_mika_snapshot ?? ($trip->owner?->getKomisiPerMikaValue() ?? 1000);

            Commission::create([
                'trip_id' => $trip->id,
                'operator_id' => $trip->operator_id,
                'cash_collected_reported' => $cashCollectedReported,
                'margin_rate_assumed' => $marginRateAssumed,
                'commission_rate' => 0.2000,
                'status' => 'paid',
                'paid_at' => now(),
                'notes' => sprintf('Komisi Rian (basis DROP): mika diletakkan (exclude BS) x Rp %d', $komisiPerMika),
            ]);
        });

        return true;
    }
}
