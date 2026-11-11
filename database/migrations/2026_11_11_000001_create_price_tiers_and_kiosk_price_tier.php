<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->cleanUpPartialRun();

        Schema::create('price_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('sale_price_per_pack', 12, 2);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            // "Exactly one default tier per variant" is enforced in PriceTier::save()/delete()
            // (transaction + row lock on the variant), covered by PriceTierResourceTest.
            // Do NOT reintroduce a STORED generated column over product_variant_id for a unique
            // index: MySQL rejects an FK with ON DELETE CASCADE on a generated column's base
            // column (error 1215), while MariaDB accepts it.
            $table->index(['owner_id', 'product_variant_id', 'is_active']);
            $table->index(['product_variant_id', 'is_default']);
        });

        DB::table('product_variants')->join('products', 'products.id', '=', 'product_variants.product_id')
            ->select('product_variants.id', 'product_variants.sale_price_per_pack', 'products.owner_id')
            ->orderBy('product_variants.id')->chunk(500, function ($variants) {
                $now = now();
                DB::table('price_tiers')->insert($variants->map(fn ($variant) => [
                    'owner_id' => $variant->owner_id,
                    'product_variant_id' => $variant->id,
                    'name' => 'Standar',
                    'sale_price_per_pack' => $variant->sale_price_per_pack,
                    'is_default' => true,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });

        // Any leftover kiosks.price_tier_id was removed by cleanUpPartialRun(), so this never double-adds.
        Schema::table('kiosks', function (Blueprint $table) {
            $table->foreignId('price_tier_id')->nullable()->constrained('price_tiers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('kiosks', 'price_tier_id')) {
            Schema::table('kiosks', fn (Blueprint $table) => $table->dropConstrainedForeignId('price_tier_id'));
        }
        Schema::dropIfExists('price_tiers');
    }

    /**
     * MySQL DDL is not transactional: a run that failed half-way leaves price_tiers and/or
     * kiosks.price_tier_id behind while the migration is not recorded. Remove such leftovers
     * only when they hold no data; otherwise stop with a clear message.
     */
    private function cleanUpPartialRun(): void
    {
        if (Schema::hasColumn('kiosks', 'price_tier_id')) {
            if (DB::table('kiosks')->whereNotNull('price_tier_id')->exists()) {
                throw new RuntimeException('kiosks.price_tier_id already exists and contains data; resolve manually before migrating.');
            }
            try {
                Schema::table('kiosks', fn (Blueprint $table) => $table->dropForeign(['price_tier_id']));
            } catch (Throwable) {
                // Foreign key was never created; only the column is left.
            }
            Schema::table('kiosks', fn (Blueprint $table) => $table->dropColumn('price_tier_id'));
        }

        if (Schema::hasTable('price_tiers')) {
            if (DB::table('price_tiers')->exists()) {
                throw new RuntimeException('Table price_tiers already exists and contains rows; refusing to drop it. Resolve manually before migrating.');
            }
            Schema::drop('price_tiers');
        }
    }
};
