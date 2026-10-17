<?php

namespace App\Console\Commands;

use App\Models\Trip;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Alat verifikasi "angka terkunci" — dump atau diff omset/hpp_estimasi/untung_kotor/
 * komisi_rian/untung_bersih_owner (per trip + agregat bulanan) satu owner, dibaca
 * LANGSUNG dari accessor Trip::* yang sedang aktif. Tidak menghitung ulang dengan
 * rumus sendiri — tujuannya mengunci OUTPUT Trip model apa adanya, supaya refactor
 * kalkulasi (snapshot per-delivery) bisa dibuktikan tidak mengubah satu rupiah pun
 * untuk data yang sudah berjalan (Ismi).
 *
 * Pemakaian:
 *   php artisan reports:verify-locked-numbers 2 --save
 *     -> dump snapshot saat ini ke storage/app/locked-numbers/owner-2.json
 *   (... jalankan migrasi/backfill/switch kalkulasi ...)
 *   php artisan reports:verify-locked-numbers 2 --before=storage/app/locked-numbers/owner-2.json
 *     -> hitung ulang & diff; exit non-zero bila ada selisih.
 */
class ReportsVerifyLockedNumbers extends Command
{
    protected $signature = 'reports:verify-locked-numbers
        {owner : ID owner}
        {--save : Simpan snapshot angka saat ini ke storage/app/locked-numbers/owner-{id}.json}
        {--before= : Path JSON snapshot sebelumnya untuk di-diff melawan angka saat ini}';

    protected $description = 'Dump atau diff angka terkunci (omset/HPP/untung/komisi) per trip & bulanan untuk satu owner.';

    private const METRICS = [
        'mika_terjual', 'omset_val', 'hpp_estimasi', 'untung_kotor',
        'mika_komisi', 'komisi_rian', 'untung_bersih_owner',
    ];

    private const TOLERANCE = 0.01; // rupiah/mika, toleransi floating-point murni

    public function handle(): int
    {
        $ownerId = (int) $this->argument('owner');
        $owner = User::withoutGlobalScopes()->find($ownerId);
        if (! $owner) {
            $this->error("Owner id {$ownerId} tidak ditemukan.");

            return self::FAILURE;
        }

        $snapshot = $this->buildSnapshot($ownerId);

        $savePath = null;
        if ($this->option('save')) {
            $savePath = storage_path('app/locked-numbers/owner-'.$ownerId.'.json');
            File::ensureDirectoryExists(dirname($savePath));
            File::put($savePath, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info("Snapshot disimpan: {$savePath}");
        }

        $beforePath = $this->option('before');
        if (! $beforePath) {
            if (! $savePath) {
                $this->printSnapshotTable($snapshot, $owner);
            }

            return self::SUCCESS;
        }

        if (! is_file($beforePath)) {
            $this->error("File --before tidak ditemukan: {$beforePath}");

            return self::FAILURE;
        }

        $before = json_decode(File::get($beforePath), true);
        if (! is_array($before)) {
            $this->error("File --before bukan JSON valid: {$beforePath}");

            return self::FAILURE;
        }

        return $this->diff($before, $snapshot, $owner) ? self::SUCCESS : self::FAILURE;
    }

    private function buildSnapshot(int $ownerId): array
    {
        $trips = Trip::where('owner_id', $ownerId)
            ->whereNotNull('ended_at')
            ->orderBy('trip_date')
            ->orderBy('trip_number_of_day')
            ->get();

        $perTrip = [];
        $monthly = [];

        foreach ($trips as $trip) {
            $row = ['trip_id' => $trip->id, 'trip_date' => $trip->trip_date->toDateString()];
            foreach (self::METRICS as $metric) {
                $row[$metric] = (float) $trip->{$metric};
            }
            $perTrip[(string) $trip->id] = $row;

            $monthKey = $trip->trip_date->format('Y-m');
            $monthly[$monthKey] ??= array_fill_keys(self::METRICS, 0.0);
            foreach (self::METRICS as $metric) {
                $monthly[$monthKey][$metric] += $row[$metric];
            }
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'owner_id' => $ownerId,
            'trip_count' => $trips->count(),
            'per_trip' => $perTrip,
            'monthly' => $monthly,
        ];
    }

    private function printSnapshotTable(array $snapshot, User $owner): void
    {
        $this->newLine();
        $this->line("===== ANGKA SAAT INI — {$owner->name} (id={$owner->id}) =====");
        $this->table(
            ['Trip', 'Tanggal', ...self::METRICS],
            array_map(
                fn ($row) => [$row['trip_id'], $row['trip_date'], ...array_map(fn ($m) => number_format($row[$m], 2), self::METRICS)],
                $snapshot['per_trip']
            )
        );
        $this->newLine();
        $this->line('--- Agregat bulanan ---');
        $this->table(
            ['Bulan', ...self::METRICS],
            array_map(
                fn ($month, $row) => [$month, ...array_map(fn ($m) => number_format($row[$m], 2), self::METRICS)],
                array_keys($snapshot['monthly']),
                $snapshot['monthly']
            )
        );
    }

    private function diff(array $before, array $after, User $owner): bool
    {
        $this->newLine();
        $this->line("===== DIFF ANGKA TERKUNCI — {$owner->name} (id={$owner->id}) =====");

        $ok = true;
        $beforeTrips = $before['per_trip'] ?? [];
        $afterTrips = $after['per_trip'] ?? [];

        $onlyBefore = array_diff(array_keys($beforeTrips), array_keys($afterTrips));
        $onlyAfter = array_diff(array_keys($afterTrips), array_keys($beforeTrips));
        if ($onlyBefore) {
            $this->warn('Trip ada di snapshot --before tapi hilang sekarang: '.implode(', ', $onlyBefore));
        }
        if ($onlyAfter) {
            $this->warn('Trip baru (tidak ada di snapshot --before): '.implode(', ', $onlyAfter));
        }

        $rows = [];
        foreach (array_intersect(array_keys($beforeTrips), array_keys($afterTrips)) as $tripId) {
            foreach (self::METRICS as $metric) {
                $old = (float) ($beforeTrips[$tripId][$metric] ?? 0);
                $new = (float) ($afterTrips[$tripId][$metric] ?? 0);
                $delta = $new - $old;
                if (abs($delta) > self::TOLERANCE) {
                    $ok = false;
                    $rows[] = ["trip #{$tripId}", $metric, number_format($old, 2), number_format($new, 2), number_format($delta, 2)];
                }
            }
        }
        foreach (array_keys($before['monthly'] ?? []) as $month) {
            foreach (self::METRICS as $metric) {
                $old = (float) ($before['monthly'][$month][$metric] ?? 0);
                $new = (float) ($after['monthly'][$month][$metric] ?? 0);
                $delta = $new - $old;
                if (abs($delta) > self::TOLERANCE) {
                    $ok = false;
                    $rows[] = ["bulan {$month}", $metric, number_format($old, 2), number_format($new, 2), number_format($delta, 2)];
                }
            }
        }

        if ($rows) {
            $this->error('DITEMUKAN SELISIH:');
            $this->table(['Lokasi', 'Metrik', 'Sebelum', 'Sesudah', 'Delta'], $rows);
        } else {
            $this->info('IDENTIK — delta 0 pada semua trip & agregat bulanan yang cocok.');
        }

        return $ok;
    }
}
