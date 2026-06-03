<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_origins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained()->cascadeOnDelete();
            $table->foreignId('settlement_id')->constrained()->cascadeOnDelete();
            $table->integer('biji_count');

            // Mencegah duplikasi data asal yang sama untuk pengiriman yang sama
            $table->unique(['delivery_id', 'settlement_id']);
        });

        // Menghapus origin_settlement_id dari tabel deliveries jika ada
        Schema::table('deliveries', function (Blueprint $table) {
            if (Schema::hasColumn('deliveries', 'origin_settlement_id')) {
                $table->dropForeign(['origin_settlement_id']);
                $table->dropColumn('origin_settlement_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->foreignId('origin_settlement_id')->nullable()->constrained('settlements')->nullOnDelete();
        });
        Schema::dropIfExists('delivery_origins');
    }
};
