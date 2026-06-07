<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Saat kios dihapus, otomatis hapus deliveries + kiosk_visits terkait.
     * settlements.delivery_id & delivery_origins.delivery_id sudah cascade,
     * jadi penghapusan ikut menjalar via deliveries.
     */
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropForeign(['kiosk_id']);
            $table->foreign('kiosk_id')->references('id')->on('kiosks')->onDelete('cascade');
        });

        Schema::table('kiosk_visits', function (Blueprint $table) {
            $table->dropForeign(['kiosk_id']);
            $table->foreign('kiosk_id')->references('id')->on('kiosks')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropForeign(['kiosk_id']);
            $table->foreign('kiosk_id')->references('id')->on('kiosks')->onDelete('restrict');
        });

        Schema::table('kiosk_visits', function (Blueprint $table) {
            $table->dropForeign(['kiosk_id']);
            $table->foreign('kiosk_id')->references('id')->on('kiosks')->onDelete('restrict');
        });
    }
};
