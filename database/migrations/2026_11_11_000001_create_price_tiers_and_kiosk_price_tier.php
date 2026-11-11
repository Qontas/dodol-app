<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('sale_price_per_pack', 12, 2);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            // NULL values may repeat; each variant may appear only once as default.
            // Supported by MariaDB/MySQL and SQLite (including the test fallback).
            $table->unsignedBigInteger('default_variant_id')
                ->nullable()->storedAs('CASE WHEN is_default = 1 THEN product_variant_id ELSE NULL END');
            $table->unique('default_variant_id');
            $table->index(['owner_id', 'product_variant_id', 'is_active']);
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

        Schema::table('kiosks', function (Blueprint $table) {
            $table->foreignId('price_tier_id')->nullable()->constrained('price_tiers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('kiosks', fn (Blueprint $table) => $table->dropConstrainedForeignId('price_tier_id'));
        Schema::dropIfExists('price_tiers');
    }
};
