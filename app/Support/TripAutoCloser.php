<?php

namespace App\Support;

use App\Models\Trip;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * BRIEF 27 Okt 2026 "TRIP LUPA DIAKHIRI" item 2 — TUTUP OTOMATIS GANTI HARI.
 *
 * Akar masalah: operator bisa lupa menekan "Akhiri Trip" sebelum pergi; trip itu
 * menggantung (ended_at NULL) selamanya kalau tidak ada pengaman — tidak masuk
 * Riwayat/Laporan Bulanan/Laporan Periode (keduanya baca trip HANYA yang sudah
 * ended_at, lihat memory "untung-bersih-formula") dan komisinya tidak tercatat.
 *
 * Trip STALE = trip_date < hari ini (zona waktu app, config('app.timezone')) DAN
 * ended_at masih NULL. Trip sentinel migrasi (Trip::isMigrationSentinel()) dan
 * trip terarsip (SoftDeletes, dikecualikan OTOMATIS oleh global scope Trip)
 * TIDAK PERNAH disentuh.
 *
 * Ditutup lewat TripCloser::close() (SATU jalur, identik tombol "Akhiri Trip")
 * dengan reason 'auto_closed' dan ended_at = waktu aktivitas TERAKHIR trip itu
 * (kunjungan/delivery/tambah stok paling akhir), fallback started_at kalau trip
 * itu tidak punya aktivitas sama sekali.
 *
 * Dipicu dari 3 jalur (lihat masing-masing pemanggil):
 *   a) App\Http\Middleware\AutoCloseStaleTrips — tiap halaman operator dibuka.
 *   b) App\Http\Middleware\AutoCloseStaleTrips — tiap halaman dashboard/Riwayat/
 *      Laporan owner dibuka.
 *   c) php artisan trips:auto-close-stale — lihat App\Console\Commands\TripsAutoCloseStale.
 */
class TripAutoCloser
{
    /**
     * Query dasar trip stale, TANPA scope owner/operator — dipakai command
     * (lintas owner, opsional --owner=N) dan oleh closeStaleForOperator()/
     * closeStaleForOwner() di bawah (mereka tambahkan where sendiri).
     *
     * SATU query SELECT — ini bagian "ringan" yang dijalankan tiap page-load
     * (lihat AutoCloseStaleTrips). Kalau hasilnya kosong (kasus normal), tidak
     * ada query tambahan sama sekali.
     */
    public static function staleQuery(): Builder
    {
        return Trip::query()
            ->realOperational()
            ->whereNull('ended_at')
            ->whereDate('trip_date', '<', today());
    }

    /** Tutup semua trip stale milik SATU operator (dipicu 2a). */
    public static function closeStaleForOperator(int $operatorId): int
    {
        return self::closeMany(
            static::staleQuery()->where('operator_id', $operatorId)->get()
        );
    }

    /** Tutup semua trip stale milik SATU owner (dipicu 2b). */
    public static function closeStaleForOwner(int $ownerId): int
    {
        return self::closeMany(
            static::staleQuery()->where('owner_id', $ownerId)->get()
        );
    }

    /**
     * @return int jumlah trip yang benar-benar ditutup (TripCloser::close()
     *             idempotent — trip yang keburu ditutup request lain tidak dihitung ulang).
     */
    private static function closeMany(Collection $trips): int
    {
        $closed = 0;

        foreach ($trips as $trip) {
            if (self::closeStaleTrip($trip)) {
                $closed++;
            }
        }

        return $closed;
    }

    /** Tutup SATU trip stale lewat TripCloser (dipakai juga command --force). */
    public static function closeStaleTrip(Trip $trip): bool
    {
        return TripCloser::close($trip, 'auto_closed', self::lastActivityAt($trip));
    }

    /**
     * Waktu aktivitas TERAKHIR trip ini (kunjungan/delivery/tambah stok paling
     * akhir) — fallback started_at kalau trip tidak punya aktivitas sama sekali
     * (operator mulai trip lalu langsung pergi, tak pernah mencatat apapun).
     */
    public static function lastActivityAt(Trip $trip): Carbon
    {
        $timestamps = collect([
            $trip->visits()->max('visited_at'),
            $trip->deliveries()->max('created_at'),
            $trip->stockAdditions()->max('created_at'),
        ])->filter()->map(fn ($t) => Carbon::parse($t));

        return $timestamps->isNotEmpty() ? $timestamps->max() : Carbon::parse($trip->started_at);
    }
}
