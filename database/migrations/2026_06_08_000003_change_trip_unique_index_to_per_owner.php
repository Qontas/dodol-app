<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Multi-tenant: nomor trip harian unik PER OWNER, bukan global.
     * Sebelumnya (trip_date, trip_number_of_day) global → dua owner tidak bisa
     * sama-sama punya "trip #1" di tanggal yang sama.
     */
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropUnique('idx_trip_date_number');
            $table->unique(['owner_id', 'trip_date', 'trip_number_of_day'], 'idx_trip_owner_date_number');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropUnique('idx_trip_owner_date_number');
            $table->unique(['trip_date', 'trip_number_of_day'], 'idx_trip_date_number');
        });
    }
};
