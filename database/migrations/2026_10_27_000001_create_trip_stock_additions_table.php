<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BRIEF 27 Okt 2026 "PENGAMAN STOK DI MOTOR" — trip "KualiStories" (26 Okt)
 * dibawa 90, dititip 107, komisi drop 107 (TANPA bs_redistribution di trip itu
 * sama sekali) → -17 itu BUKAN disebabkan Dodol Sisa (lihat investigasi lama di
 * TripExportController::buildTripData(), sekarang dikoreksi). Akar masalah
 * sebenarnya: (1) tidak ada guard yang menolak titip melebihi stok di motor,
 * (2) tidak ada jalur buat mencatat tambahan stok di tengah trip.
 *
 * trips.qty_carried_total TETAP = stok awal (tidak pernah ditimpa). Tabel ini
 * mencatat tambahan stok SETELAH trip mulai — stok efektif dibawa = qty_carried_total
 * + SUM(trip_stock_additions.qty). Lihat Trip::getStokDiMotor() (SATU sumber
 * kebenaran, dipakai di semua tempat).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_stock_additions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')
                ->constrained('trips')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('qty');
            $table->foreignId('added_by')
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['trip_id', 'created_at'], 'idx_trip_recent_stock_additions');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_stock_additions');
    }
};
