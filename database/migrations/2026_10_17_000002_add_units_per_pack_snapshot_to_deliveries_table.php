<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Isi per kemasan (biji/mika) SNAPSHOT pada saat delivery ini dibuat — bukan isi
 * produk SAAT INI. Owner bisa ubah isi varian kapan saja; titipan lama harus tetap
 * dihitung dengan isi yang berlaku saat dititip, bukan ikut berubah retroaktif.
 * Nullable, backfill lewat `deliveries:backfill-snapshot` untuk baris lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->smallInteger('units_per_pack_snapshot')->nullable()->after('qty_delivered');
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn('units_per_pack_snapshot');
        });
    }
};
