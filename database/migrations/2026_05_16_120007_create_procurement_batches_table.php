<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')
                ->constrained('product_variants')
                ->restrictOnDelete();
            $table->foreignId('supplier_id')
                ->constrained('suppliers')
                ->restrictOnDelete();

            $table->date('purchase_date');
            $table->decimal('qty_kg_raw', 10, 2);
            $table->smallInteger('qty_packs');

            $table->decimal('cost_raw_material', 12, 2);
            $table->decimal('cost_packing', 12, 2)->default(0);
            $table->decimal('cost_packaging', 12, 2)->default(0);
            $table->decimal('cost_other', 12, 2)->default(0);

            $table->decimal('total_cost', 12, 2)
                ->storedAs('cost_raw_material + cost_packing + cost_packaging + cost_other');
            $table->decimal('cost_per_pack', 12, 2)
                ->storedAs('(cost_raw_material + cost_packing + cost_packaging + cost_other) / NULLIF(qty_packs, 0)');
            $table->decimal('price_per_kg_raw', 12, 2)
                ->storedAs('cost_raw_material / NULLIF(qty_kg_raw, 0)');

            $table->date('expiry_date');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('purchase_date');
            $table->index('expiry_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_batches');
    }
};
