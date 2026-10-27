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

        $query = TripAutoCloser::staleQuery()->with(['operator', 'owner'])->orderBy('trip_date');
        if ($ownerId !== null) {
            $query->where('owner_id', $ownerId);
        }

        $staleTrips = $query->get();

        if ($staleTrips->isEmpty()) {
            $this->info('Tidak ada trip stale (lupa diakhiri) ditemukan.');

            return self::SUCCESS;
        }

        $rows = $staleTrips->map(fn ($trip) => [
            $trip->id,
            $trip->trip_date->format('d M Y'),
            'Trip #'.$trip->trip_number_of_day,
            $trip->owner?->name ?? '—',
            $trip->operator?->name ?? '—',
            $trip->getTotalDropReal().' mika',
            'Rp '.number_format($trip->komisi_rian, 0, ',', '.'),
        ])->all();

        $this->line(sprintf(
            '%s %d trip stale (trip_date < hari ini, belum diakhiri):',
            $force ? '[FORCE]' : '[DRY-RUN]',
            $staleTrips->count()
        ));
        $this->table(
            ['ID', 'Tanggal', 'Trip', 'Owner', 'Operator', 'Mika Diletakkan', 'Komisi'],
            $rows
        );

        if (! $force) {
            $this->newLine();
            $this->comment('Dry-run saja — tidak ada perubahan data. Tambahkan --force untuk benar-benar menutup.');

            return self::SUCCESS;
        }

        $closed = 0;
        foreach ($staleTrips as $trip) {
            if (TripAutoCloser::closeStaleTrip($trip)) {
                $closed++;
            }
        }

        $this->newLine();
        $this->info("Selesai — {$closed} trip ditutup otomatis (ended_reason='auto_closed').");

        return self::SUCCESS;
    }
}
