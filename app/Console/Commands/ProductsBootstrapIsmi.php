<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Siapkan Product+ProductVariant Ismi (owner_id=2) sebagai pemilik data existing
 * sebelum refactor "isi/harga/HPP per produk". Ismi sudah operasional lama dengan
 * konstanta global (15 biji/mika, Rp12.000, HPP dari users.hpp_per_mika) — command
 * ini TIDAK mengubah angka itu, hanya memindahkannya ke kolom produk yang baru
 * (cost_per_pack) supaya snapshot delivery ke depan punya sumber yang benar.
 *
 * Idempoten & aman dijalankan berkali-kali:
 * - Product/Variant yang SUDAH ADA tidak dibuat ulang (tidak duplikat).
 * - units_per_pack & sale_price_per_pack variant yang SUDAH ADA tidak pernah
 *   ditimpa (itu data real, bukan boilerplate) — hanya cost_per_pack yang
 *   diisi, dan HANYA bila masih NULL.
 * - Default dry-run (laporan saja); --force untuk benar-benar menyimpan.
 */
class ProductsBootstrapIsmi extends Command
{
    protected $signature = 'products:bootstrap-ismi {--force : Simpan perubahan (default: dry-run)}';

    protected $description = 'Bootstrap Product+ProductVariant Ismi (owner_id=2) dengan cost_per_pack dari hpp_per_mika saat ini.';

    private const OWNER_ID = 2;

    private const DEFAULT_PRODUCT_NAME = 'Dodol Coklat Susu';

    private const DEFAULT_VARIANT_NAME = 'Mika 15 biji';

    private const DEFAULT_UNITS_PER_PACK = 15;

    private const DEFAULT_SALE_PRICE = 12000;

    private const DEFAULT_COST_FALLBACK = 9500;

    public function handle(): int
    {
        $owner = User::withoutGlobalScopes()->find(self::OWNER_ID);
        if (! $owner) {
            $this->error('Owner id '.self::OWNER_ID.' (Ismi) tidak ditemukan.');

            return self::FAILURE;
        }
        if ($owner->email !== 'owner@cemilanqontas.id') {
            $this->warn("Peringatan: email owner id ".self::OWNER_ID." adalah \"{$owner->email}\", bukan owner@cemilanqontas.id yang diharapkan. Lanjut dengan asumsi ID benar.");
        }

        $force = (bool) $this->option('force');

        $product = Product::withoutGlobalScopes()->where('owner_id', self::OWNER_ID)->first();
        $variant = $product
            ? ProductVariant::withoutGlobalScopes()->where('product_id', $product->id)->first()
            : null;

        // cost_per_pack target dibaca LIVE dari hpp_per_mika Ismi saat ini (bukan
        // hardcode) — toleran bila nilainya pernah diubah owner lewat Settings lama.
        $targetCost = (float) ($owner->hpp_per_mika ?? self::DEFAULT_COST_FALLBACK);

        $productAction = $product ? 'SUDAH ADA (dipakai, tidak dibuat ulang)' : 'akan DIBUAT baru';
        $variantAction = match (true) {
            ! $product => 'akan DIBUAT baru (ikut product baru)',
            ! $variant => 'akan DIBUAT baru',
            $variant->cost_per_pack !== null => 'SUDAH ADA, cost_per_pack sudah terisi — tidak disentuh',
            default => 'SUDAH ADA, cost_per_pack NULL — akan diisi Rp '.number_format($targetCost, 0, ',', '.'),
        };

        $this->newLine();
        $this->line('===== RINGKASAN '.($force ? 'BOOTSTRAP' : 'DRY-RUN').' PRODUK ISMI =====');
        $this->table(['Metrik', 'Nilai'], [
            ['Owner', "{$owner->name} ({$owner->email}, id=".self::OWNER_ID.')'],
            ['hpp_per_mika saat ini', 'Rp '.number_format((float) ($owner->hpp_per_mika ?? 0), 0, ',', '.')],
            ['Product', $product ? "#{$product->id} \"{$product->name}\"" : '(belum ada)'],
            ['Aksi Product', $productAction],
            ['Variant', $variant ? "#{$variant->id} \"{$variant->name}\" (units={$variant->units_per_pack}, harga={$variant->sale_price_per_pack}, cost=".($variant->cost_per_pack ?? 'NULL').')' : '(belum ada)'],
            ['Aksi Variant', $variantAction],
            ['Target cost_per_pack', 'Rp '.number_format($targetCost, 0, ',', '.')],
        ]);

        if (! $force) {
            $this->newLine();
            $this->info('DRY-RUN: tidak ada data disimpan. Jalankan ulang dengan --force untuk commit.');

            return self::SUCCESS;
        }

        DB::transaction(function () use (&$product, &$variant, $owner, $targetCost) {
            if (! $product) {
                $product = Product::create([
                    'owner_id' => self::OWNER_ID,
                    'name' => self::DEFAULT_PRODUCT_NAME,
                    'is_active' => true,
                ]);
            }

            if (! $variant) {
                $variant = ProductVariant::create([
                    'product_id' => $product->id,
                    'name' => self::DEFAULT_VARIANT_NAME,
                    'units_per_pack' => self::DEFAULT_UNITS_PER_PACK,
                    'sale_price_per_pack' => self::DEFAULT_SALE_PRICE,
                    'cost_per_pack' => $targetCost,
                    'is_active' => true,
                ]);
            } elseif ($variant->cost_per_pack === null) {
                $variant->cost_per_pack = $targetCost;
                $variant->save();
            }
        });

        $this->newLine();
        $this->info("SELESAI: Product #{$product->id}, Variant #{$variant->id} (owner {$owner->name}).");

        return self::SUCCESS;
    }
}
