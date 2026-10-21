<?php

namespace App\Console\Commands;

use App\Models\Trip;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * BRIEF 2 (21 Okt 2026) — Section 2. Backfill trips.komisi_per_mika_snapshot untuk
 * trip LAMA (dibuat sebelum kolom snapshot ada) milik satu owner.
 *
 * Fitur per-operator "tarif khusus" (Section 3) belum ada saat trip-trip lama ini
 * dibuat — jadi tarif yang BERLAKU saat itu, bagi SEMUA trip lama, memang selalu
 * tarif owner. Backfill ini mengisi snapshot dengan tarif owner SAAT INI (satu-
 * satunya tarif yang pernah berlaku untuk trip lama), persis seperti nilai yang
 * SUDAH dibaca live selama ini oleh Trip::getKomisiPerMika() — jadi backfill murni
 * "menuliskan apa yang sudah jadi hasil tampilan", TIDAK mengubah satu rupiah pun.
 * Pembuktiannya: `reports:verify-locked-numbers {owner} --before=...` harus IDENTIK
 * sebelum & sesudah backfill ini (kode kalkulasi belum diubah di commit ini).
 *
 * Dipicu HANYA oleh komisi_per_mika_snapshot IS NULL — trip yang sudah punya nilai
 * (dibuat sesudah StartTrip mulai menulis snapshot, fase 2) otomatis tidak tersentuh.
 *
 * Default dry-run; --force untuk commit. --dry-run eksplisit selalu menang atas
 * --force (safety override, sama seperti deliveries:backfill-snapshot).
 */
class TripsBackfillKomisiSnapshot extends Command
{
    protected $signature = 'trips:backfill-komisi-snapshot
        {--owner= : ID owner (wajib)}
        {--dry-run : Paksa mode laporan saja, menang atas --force}
        {--force : Simpan perubahan (default: dry-run)}
        {--chunk=100 : Ukuran batch per transaksi}';

    protected $description = 'Backfill komisi_per_mika_snapshot untuk trip lama (masih NULL) milik satu owner, dengan tarif owner SAAT INI.';

    public function handle(): int
    {
        $ownerId = $this->option('owner') !== null ? (int) $this->option('owner') : null;
        if ($ownerId === null) {
            $this->error('Wajib --owner=<id>.');

            return self::FAILURE;
        }

        $owner = User::withoutGlobalScopes()->find($ownerId);
        if (! $owner) {
            $this->error("Owner id {$ownerId} tidak ditemukan.");

            return self::FAILURE;
        }

        $chunkSize = max(1, (int) $this->option('chunk'));
        $commit = (bool) $this->option('force') && ! $this->option('dry-run');

        $rate = $owner->getKomisiPerMikaValue();

        // withTrashed(): trip terarsip (SoftDeletes) tetap diberi snapshot — bisa
        // dipulihkan lewat `trip:restore` dan harus tetap benar kalau itu terjadi.
        $query = Trip::withTrashed()
            ->where('owner_id', $ownerId)
            ->whereNull('komisi_per_mika_snapshot');

        $total = $query->count();

        $this->newLine();
        $this->line('===== RINGKASAN '.($commit ? 'BACKFILL' : 'DRY-RUN').' SNAPSHOT KOMISI TRIP =====');
        $this->table(['Metrik', 'Nilai'], [
            ['Owner', "{$owner->name} (id={$ownerId})"],
            ['Trip dengan komisi_per_mika_snapshot NULL', $total],
            ['  -> akan diisi komisi_per_mika_snapshot', 'Rp '.number_format($rate, 0, ',', '.').' (tarif owner saat ini)'],
            ['Ukuran chunk', $chunkSize],
        ]);

        if ($total === 0) {
            $this->newLine();
            $this->info('Tidak ada trip yang perlu di-backfill (semua sudah punya komisi_per_mika_snapshot).');

            return self::SUCCESS;
        }

        if (! $commit) {
            $this->newLine();
            $this->info('DRY-RUN: tidak ada data disimpan. Jalankan ulang dengan --force (tanpa --dry-run) untuk commit.');

            return self::SUCCESS;
        }

        $updated = 0;
        $query->chunkById($chunkSize, function ($trips) use (&$updated, $rate) {
            DB::transaction(function () use ($trips, &$updated, $rate) {
                foreach ($trips as $trip) {
                    $trip->komisi_per_mika_snapshot = $rate;
                    $trip->save();
                    $updated++;
                }
            });
            $this->info("  ... {$updated} trip di-backfill");
        });

        $this->newLine();
        $this->info("SELESAI: {$updated} trip di-backfill untuk owner {$owner->name}.");

        return self::SUCCESS;
    }
}
