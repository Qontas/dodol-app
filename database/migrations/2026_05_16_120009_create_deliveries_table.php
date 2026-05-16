<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiosk_id')
                ->constrained('kiosks')
                ->restrictOnDelete();
            $table->foreignId('trip_id')
                ->constrained('trips')
                ->cascadeOnDelete();
            $table->foreignId('product_variant_id')
                ->constrained('product_variants')
                ->restrictOnDelete();
            $table->foreignId('procurement_batch_id')->nullable()
                ->constrained('procurement_batches')
                ->restrictOnDelete();

            $table->enum('source_type', ['new_procurement', 'fresh_return_redeploy'])
                ->default('new_procurement');
            // origin_settlement_id ditambah via migration #12 setelah settlements table ada
            $table->unsignedBigInteger('origin_settlement_id')->nullable();

            $table->enum('delivery_type', ['consignment', 'cash_sale'])->default('consignment');
            $table->smallInteger('qty_delivered');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('cost_snapshot', 12, 2)->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('source_type');
            $table->index(['kiosk_id', 'created_at'], 'idx_kiosk_recent_deliveries');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliveries');
    }
};
