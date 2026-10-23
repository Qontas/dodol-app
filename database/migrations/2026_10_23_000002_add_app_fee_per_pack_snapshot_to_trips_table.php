<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BRIEF (23 Okt 2026) TUGAS 4a — snapshot tarif fee aplikasi per mika, diisi
 * StartTrip dari owner.app_fee_per_pack saat trip dimulai (pola sama
 * komisi_per_mika_snapshot). Kalau tarif owner berubah belakangan, trip yang
 * SUDAH berjalan/selesai tetap pakai tarif lama — tidak retroaktif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->decimal('app_fee_per_pack_snapshot', 12, 2)->nullable()->after('komisi_per_mika_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn('app_fee_per_pack_snapshot');
        });
    }
};
