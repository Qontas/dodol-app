<?php

namespace App\Console\Commands;

use App\Models\Trip;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * BRIEF (23 Okt 2026) TUGAS 4d — Backfill trips.app_fee_per_pack_snapshot untuk
 * trip LAMA (dibuat sebelum kolom snapshot ini ada, atau sebelum fee aplikasi
 * mulai berlaku untuk owner tsb) milik satu owner.
 *
 * BEDA dari trips:backfill-komisi-snapshot: tarif di SINI wajib diberikan lewat
 * --rate, BUKAN dibaca dari users.app_fee_per_pack saat ini — karena fee aplikasi
 * adalah kesepakatan bisnis BARU (Rp 300/kemasan mulai TUGAS 4), bukan tarif yang
 * "sudah berlaku diam-diam" sejak dulu seperti komisi Rian. Operator harus secara
 * sadar menuliskan tarif historis yang disepakati, bukan ikut tarif hari ini.
 *
 * Dipicu HANYA oleh app_fee_per_pack_snapshot IS NULL — trip yang sudah punya
 * nilai (dibuat sesudah StartTrip mulai menulis snapshot) TIDAK pernah ditimpa,
 * sesuai aturan keras "angka uang lama tidak boleh berubah": backfill ini hanya
 * MENGISI kekosongan, tidak pernah menimpa nilai yang sudah ada.
 *
 * Default dry-run; --force untuk commit. --dry-run eksplisit selalu menang atas
 * --force (safety override, sama seperti trips:backfill-komisi-snapshot).
 */
class TripsBackfillAppFeeSnapshot extends Command
{
    protected $signature = 'trips:backfill-app-fee-snapshot
        {--owner= : ID owner (wajib)}
        {--rate= : Tarif fee aplikasi Rp per kemasan (wajib)}
        {--dry-run : Paksa mode laporan saja, menang atas --force}
        {--force : Simpan perubahan (default: dry-run)}
        {--chunk=100 : Ukuran batch per transaksi}';

    protected $description = 'Backfill app_fee_per_pack_snapshot untuk trip lama (masih NULL) milik satu owner, dengan tarif yang diberikan lewat --rate.';

    public function handle(): int
    {
        $ownerId = $this->option('owner') !== null ? (int) $this->option('owner') : null;
        if ($ownerId === null) {
            $this->error('Wajib --owner=<id>.');

            return self::FAILURE;
        }

        if ($this->option('rate') === null || ! is_numeric($this->option('rate'))) {
            $this->error('Wajib --rate=<angka> (Rp per kemasan).');

            return self::FAILURE;
        }
        $rate = (float) $this->option('rate');
        if ($rate < 0) {
            $this->error('--rate tidak boleh negatif.');

            return self::FAILURE;
        }

        $owner = User::withoutGlobalScopes()->find($ownerId);
        if (! $owner) {
            $this->error("Owner id {$ownerId} tidak ditemukan.");

            return self::FAILURE;
        }

        $chunkSize = max(1, (int) $this->option('chunk'));
        $commit = (bool) $this->option('force') && ! $this->option('dry-run');

        // withTrashed(): trip terarsip (SoftDeletes) tetap diberi snapshot — bisa
        // dipulihkan lewat `trip:restore` dan harus tetap benar kalau itu terjadi.
        // whereNull: TIDAK PERNAH menimpa nilai yang sudah ada (aturan keras brief).
        $query = Trip::withTrashed()
            ->where('owner_id', $ownerId)
            ->whereNull('app_fee_per_pack_snapshot');

        $total = $query->count();

        $this->newLine();
        $this->line('===== RINGKASAN '.($commit ? 'BACKFILL' : 'DRY-RUN').' SNAPSHOT FEE APLIKASI TRIP =====');
        $this->table(['Metrik', 'Nilai'], [
            ['Owner', "{$owner->name} (id={$ownerId})"],
            ['Trip dengan app_fee_per_pack_snapshot NULL', $total],
            ['  -> akan diisi app_fee_per_pack_snapshot', 'Rp '.number_format($rate, 0, ',', '.').' (dari --rate)'],
            ['Ukuran chunk', $chunkSize],
        ]);

        if ($total === 0) {
            $this->newLine();
            $this->info('Tidak ada trip yang perlu di-backfill (semua sudah punya app_fee_per_pack_snapshot).');

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
                    $trip->app_fee_per_pack_snapshot = $rate;
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
