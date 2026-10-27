<?php

namespace App\Support;

use App\Models\Trip;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * BRIEF 27 Okt 2026 "TRIP LUPA DIAKHIRI" item 2 — TUTUP OTOMATIS GANTI HARI.
 * Lanjutan BRIEF (syarat idle minimal): lihat docblock isIdleLongEnough().
 *
 * Akar masalah: operator bisa lupa menekan "Akhiri Trip" sebelum pergi; trip itu
 * menggantung (ended_at NULL) selamanya kalau tidak ada pengaman — tidak masuk
 * Riwayat/Laporan Bulanan/Laporan Periode (keduanya baca trip HANYA yang sudah
 * ended_at, lihat memory "untung-bersih-formula") dan komisinya tidak tercatat.
 *
 * Trip STALE = KEDUA syarat ini terpenuhi:
 *   a) trip_date < hari ini (zona waktu app, config('app.timezone')) DAN
 *      ended_at masih NULL — dicek staleQuery(), SATU query SELECT ringan.
 *   b) aktivitas TERAKHIRnya (lastActivityAt()) sudah lebih dari N jam yang lalu
 *      (config('trips.auto_close_idle_hours'), default 6) — dicek
 *      isIdleLongEnough(). Syarat ini mencegah trip yang MASIH dipakai operator
 *      mengantar lewat tengah malam ikut tertutup begitu ia membuka halaman
 *      sesaat sesudah jam 00:00 (trip_date-nya sudah "kemarin" tapi ia masih
 *      aktif mencatat kunjungan/titip/tambah stok).
 *
 * Trip sentinel migrasi (Trip::isMigrationSentinel()) dan trip terarsip
 * (SoftDeletes, dikecualikan OTOMATIS oleh global scope Trip) TIDAK PERNAH
 * disentuh — sudah difilter di staleQuery() (realOperational()).
 *
 * Ditutup lewat TripCloser::close() (SATU jalur, identik tombol "Akhiri Trip")
 * dengan reason 'auto_closed' dan ended_at = waktu aktivitas TERAKHIR trip itu
 * (kunjungan/delivery/tambah stok paling akhir), fallback started_at kalau trip
 * itu tidak punya aktivitas sama sekali — TIDAK berubah oleh syarat idle di atas,
 * ended_at tetap mencatat kapan trip itu SUNGGUH berhenti, bukan kapan ia dicek.
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
     * Query dasar trip stale (syarat tanggal SAJA — syarat idle dicek belakangan
     * per-trip lewat isIdleLongEnough(), lihat closeStaleTrip()), TANPA scope
     * owner/operator — dipakai command (lintas owner, opsional --owner=N) dan
     * oleh closeStaleForOperator()/closeStaleForOwner() di bawah (mereka
     * tambahkan where sendiri).
     *
     * SATU query SELECT — ini bagian "ringan" yang dijalankan tiap page-load
     * (lihat AutoCloseStaleTrips). Kalau hasilnya kosong (kasus normal, trip
     * yang masih aktif hari ini belum "lewat tanggal"), tidak ada query
     * tambahan sama sekali — syarat idle baru dihitung untuk KANDIDAT yang
     * memang sudah ganti hari, yang seharusnya jarang.
     */
    public static function staleQuery(): Builder
    {
        return Trip::query()
            ->realOperational()
            ->whereNull('ended_at')
            ->whereDate('trip_date', '<', today());
    }

    /** Ambang jam idle minimal sebelum trip boleh ditutup otomatis (lihat isIdleLongEnough()). */
    public static function idleHoursThreshold(): int
    {
        return (int) config('trips.auto_close_idle_hours', 6);
    }

    /**
     * Info idle satu trip kandidat (trip_date-nya sudah lewat, lihat
     * staleQuery()) — dipakai BERSAMA oleh closeStaleTrip() (keputusan tutup
     * atau tidak) dan command dry-run (kolom "Aktivitas terakhir"/"Tidak aktif
     * (jam)"), supaya kedua tempat itu selalu sepakat angka yang sama.
     *
     * @return array{last_activity_at: Carbon, idle_hours: float, is_idle_long_enough: bool}
     */
    public static function idleInfo(Trip $trip, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $lastActivityAt = self::lastActivityAt($trip);
        $idleMinutes = $lastActivityAt->diffInMinutes($now);

        return [
            'last_activity_at' => $lastActivityAt,
            'idle_hours' => round($idleMinutes / 60, 1),
            // "LEBIH dari N jam" → perbandingan ketat, bukan >=. Trip yang
            // idle-nya PAS N jam belum memenuhi syarat.
            'is_idle_long_enough' => $idleMinutes > self::idleHoursThreshold() * 60,
        ];
    }

    /**
     * Trip ini sudah tidak aktif LEBIH dari idleHoursThreshold() jam, dihitung
     * dari aktivitas TERAKHIRnya — syarat KEDUA auto-close (lihat docblock
     * kelas), SELAIN trip_date < hari ini. Operator yang mengantar lewat
     * tengah malam (trip_date "kemarin" tapi masih aktif mencatat) TIDAK
     * ditutup selama ia masih di bawah ambang ini.
     */
    public static function isIdleLongEnough(Trip $trip, ?Carbon $now = null): bool
    {
        return self::idleInfo($trip, $now)['is_idle_long_enough'];
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

    /**
     * Tutup SATU trip stale lewat TripCloser (dipakai juga command --force) —
     * TAPI hanya kalau syarat idle (isIdleLongEnough()) juga terpenuhi. Trip
     * yang trip_date-nya sudah lewat TAPI masih aktif < N jam (operator
     * mengantar lewat tengah malam) dibiarkan — return false, bukan ditutup.
     */
    public static function closeStaleTrip(Trip $trip): bool
    {
        $info = self::idleInfo($trip);

        if (! $info['is_idle_long_enough']) {
            return false;
        }

        return TripCloser::close($trip, 'auto_closed', $info['last_activity_at']);
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
