<?php

namespace App\Console\Commands;

use App\Support\TripAutoCloser;
use Illuminate\Console\Command;

/**
 * BRIEF 27 Okt 2026 "TRIP LUPA DIAKHIRI" item 2c — jalur manual (belum ada
 * scheduler/cron di Railway) untuk menutup trip yang lupa diakhiri operator.
 * Default DRY-RUN (hanya menampilkan daftar); --force untuk benar-benar menutup
 * (lewat TripAutoCloser::closeStaleTrip() -> TripCloser::close(), jalur identik
 * tombol "Akhiri Trip").
 *
 * BRIEF lanjutan (syarat idle minimal) — trip_date < hari ini SAJA tidak cukup:
 * trip itu juga harus tidak aktif LEBIH dari config('trips.auto_close_idle_hours')
 * jam (lihat TripAutoCloser::idleInfo()), supaya trip yang MASIH dipakai operator
 * mengantar lewat tengah malam tidak ikut ditutup. Trip kandidat (trip_date sudah
 * lewat) yang BELUM memenuhi syarat idle ini TIDAK ditampilkan sama sekali di
 * dry-run — bukan ditampilkan lalu dilewati, supaya daftar hanya berisi trip yang
 * memang akan/boleh ditutup.
 *
 * Pemakaian:
 *   php artisan trips:auto-close-stale            -> dry-run SEMUA owner
 *   php artisan trips:auto-close-stale --owner=3  -> dry-run satu owner
 *   php artisan trips:auto-close-stale --force    -> benar-benar tutup
 */
class TripsAutoCloseStale extends Command
{
    protected $signature = 'trips:auto-close-stale
        {--owner= : Batasi ke satu owner_id (default semua owner)}
        {--force : Benar-benar tutup trip stale (tanpa ini, hanya dry-run/daftar)}';

    protected $description = 'Tutup trip yang trip_date-nya sudah lewat & lupa diakhiri operator (default dry-run).';

    public function handle(): int
    {
        $ownerId = $this->option('owner') !== null ? (int) $this->option('owner') : null;
        $force = (bool) $this->option('force');
        $idleHours = TripAutoCloser::idleHoursThreshold();

        $query = TripAutoCloser::staleQuery()->with(['operator', 'owner'])->orderBy('trip_date');
        if ($ownerId !== null) {
            $query->where('owner_id', $ownerId);
        }

        // Kandidat tanggal (trip_date < hari ini) — syarat idle dicek PER TRIP
        // di bawah, karena bergantung aktivitas (kunjungan/delivery/tambah stok)
        // yang tidak bisa dihitung dalam satu query SELECT sederhana.
        $candidates = $query->get();

        // Pasangkan tiap trip dengan info idle-nya SEKALI di sini supaya nomor
        // yang ditampilkan ("Tidak aktif (jam)") dan nomor yang menentukan
        // ditutup/tidaknya (--force) PASTI sama — tidak dihitung ulang dua kali
        // dengan now() yang mungkin berbeda sepersekian detik.
        $staleEnough = $candidates
            ->map(fn ($trip) => ['trip' => $trip, 'info' => TripAutoCloser::idleInfo($trip)])
            ->filter(fn ($row) => $row['info']['is_idle_long_enough'])
            ->values();

        if ($staleEnough->isEmpty()) {
            $this->info('Tidak ada trip stale (lupa diakhiri) ditemukan.');

            return self::SUCCESS;
        }

        $rows = $staleEnough->map(function ($row) {
            $trip = $row['trip'];

            return [
                $trip->id,
                $trip->trip_date->format('d M Y'),
                'Trip #'.$trip->trip_number_of_day,
                $trip->owner?->name ?? '—',
                $trip->operator?->name ?? '—',
                $trip->getTotalDropReal().' mika',
                'Rp '.number_format($trip->komisi_rian, 0, ',', '.'),
                $row['info']['last_activity_at']->format('d M Y H:i'),
                $row['info']['idle_hours'].' jam',
            ];
        })->all();

        $this->line(sprintf(
            '%s %d trip stale (trip_date < hari ini & tidak aktif > %d jam, belum diakhiri):',
            $force ? '[FORCE]' : '[DRY-RUN]',
            $staleEnough->count(),
            $idleHours
        ));
        $this->table(
            ['ID', 'Tanggal', 'Trip', 'Owner', 'Operator', 'Mika Diletakkan', 'Komisi', 'Aktivitas Terakhir', 'Tidak Aktif (Jam)'],
            $rows
        );

        if (! $force) {
            $this->newLine();
            $this->comment('Dry-run saja — tidak ada perubahan data. Tambahkan --force untuk benar-benar menutup.');

            return self::SUCCESS;
        }

        $closed = 0;
        foreach ($staleEnough as $row) {
            if (TripAutoCloser::closeStaleTrip($row['trip'])) {
                $closed++;
            }
        }

        $this->newLine();
        $this->info("Selesai — {$closed} trip ditutup otomatis (ended_reason='auto_closed').");

        return self::SUCCESS;
    }
}
