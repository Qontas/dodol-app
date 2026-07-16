<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            // ARSIP trip (soft delete). Tombol "Arsipkan" di dashboard mengisi kolom ini;
            // SoftDeletes global scope model Trip menyembunyikannya dari SEMUA laporan &
            // agregat (omset/untung/komisi) TANPA menghapus data anak (kiosk_visits,
            // deliveries, settlements, commissions) secara fisik. Bisa dipulihkan via
            // `php artisan trip:restore {id}`.
            $table->softDeletes()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
