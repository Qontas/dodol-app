<?php

namespace App\Console\Commands;

use App\Models\Delivery;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill units_per_pack_snapshot/unit_price/cost_snapshot untuk delivery LAMA
 * (dibuat sebelum kolom snapshot ada) milik satu owner. Dipicu HANYA oleh
 * units_per_pack_snapshot IS NULL — delivery yang sudah punya nilai (termasuk
 * delivery bs_redistribution dengan cost_snapshot=0 yang disengaja) otomatis
 * TIDAK tersentuh, karena delivery itu pasti dibuat sesudah kolom snapshot ada
 * dan sudah terisi oleh kode titik-create yang benar.
 *
 * 🔴 Aturan tak boleh dilanggar (angka Ismi tidak boleh bergeser):
 * - unit_price HANYA diisi bila NULL (tidak pernah menimpa nilai yang sudah ada).
 * - cost_snapshot HANYA diisi bila NULL (tidak pernah menimpa 0 yang disengaja
 *   atau nilai lain yang sudah ada).
 * - units_per_pack_snapshot diisi dari varian aktif owner (atau fallback 15 bila
 *   owner belum punya varian) — nilai ini IDENTIK dengan fallback yang sudah
 *   dipakai Delivery::unitsPerPackSnapshotOrFallback(), jadi backfill ini murni
 *   "menuliskan apa yang sudah jadi asumsi default" — tidak mengubah hasil hitung
 *   apa pun yang sudah berjalan.
 *
 * Default dry-run; --force untuk commit. --dry-run eksplisit selalu menang atas
 * --force (safety override).
 */
class DeliveriesBackfillSnapshot extends Command
{
    protected $signature = 'deliveries:backfill-snapshot
        {--owner= : ID owner (wajib)}
        {--dry-run : Paksa mode laporan saja, menang atas --force}
        {--force : Simpan perubahan (default: dry-run)}
        {--chunk=100 : Ukuran batch per transaksi}';

    protected $description = 'Backfill snapshot isi/harga/HPP untuk delivery lama (units_per_pack_snapshot masih NULL) milik satu owner.';

    private const FALLBACK_UNITS = 15;

    private const FALLBACK_PRICE = 12000;

    private const FALLBACK_COST = 9500;

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

        // Sumber fallback: varian aktif owner ini bila ada (supaya command tetap
        // reusable untuk owner lain di masa depan), jatuh ke konstanta historis
        // Ismi (15/12000/9500) bila owner belum punya varian produk sama sekali.
        $variant = ProductVariant::withoutGlobalScopes()
            ->where('is_active', true)
            ->whereHas('product', fn ($q) => $q->where('owner_id', $ownerId))
            ->first();

        $fallbackUnits = $variant ? (int) $variant->units_per_pack : self::FALLBACK_UNITS;
        $fallbackPrice = $variant ? (float) $variant->sale_price_per_pack : self::FALLBACK_PRICE;
        $fallbackCost = $variant && $variant->cost_per_pack !== null ? (float) $variant->cost_per_pack : self::FALLBACK_COST;

        $query = Delivery::query()
            ->whereNull('units_per_pack_snapshot')
            ->whereHas('trip', fn ($q) => $q->where('owner_id', $ownerId));

        $total = $query->count();
        $nullUnitPrice = (clone $query)->whereNull('unit_price')->count();
        $nullCostSnapshot = (clone $query)->whereNull('cost_snapshot')->count();

        $this->newLine();
        $this->line('===== RINGKASAN '.($commit ? 'BACKFILL' : 'DRY-RUN').' SNAPSHOT DELIVERY =====');
        $this->table(['Metrik', 'Nilai'], [
            ['Owner', "{$owner->name} (id={$ownerId})"],
            ['Delivery dengan units_per_pack_snapshot NULL', $total],
            ['  -> akan diisi units_per_pack_snapshot', $fallbackUnits],
            ['  -> dari sejumlah itu, unit_price juga NULL (akan diisi)', $nullUnitPrice.' @ Rp '.number_format($fallbackPrice, 0, ',', '.')],
            ['  -> dari sejumlah itu, cost_snapshot juga NULL (akan diisi)', $nullCostSnapshot.' @ Rp '.number_format($fallbackCost, 0, ',', '.')],
            ['Sumber fallback', $variant ? "varian aktif #{$variant->id} ({$variant->name})" : 'konstanta historis (tidak ada varian aktif)'],
            ['Ukuran chunk', $chunkSize],
        ]);

        if ($total === 0) {
            $this->newLine();
            $this->info('Tidak ada delivery yang perlu di-backfill (semua sudah punya units_per_pack_snapshot).');

            return self::SUCCESS;
        }

        if (! $commit) {
            $this->newLine();
            $this->info('DRY-RUN: tidak ada data disimpan. Jalankan ulang dengan --force (tanpa --dry-run) untuk commit.');

            return self::SUCCESS;
        }

        $updated = 0;
        $query->chunkById($chunkSize, function ($deliveries) use (&$updated, $fallbackUnits, $fallbackPrice, $fallbackCost) {
            DB::transaction(function () use ($deliveries, &$updated, $fallbackUnits, $fallbackPrice, $fallbackCost) {
                foreach ($deliveries as $delivery) {
                    $delivery->units_per_pack_snapshot = $fallbackUnits;
                    if ($delivery->unit_price === null) {
                        $delivery->unit_price = $fallbackPrice;
                    }
                    if ($delivery->cost_snapshot === null) {
                        $delivery->cost_snapshot = $fallbackCost;
                    }
                    $delivery->save();
                    $updated++;
                }
            });
            $this->info("  ... {$updated} delivery di-backfill");
        });

        $this->newLine();
        $this->info("SELESAI: {$updated} delivery di-backfill untuk owner {$owner->name}.");

        return self::SUCCESS;
    }
}
