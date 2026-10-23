<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BRIEF (23 Okt 2026) TUGAS 4a — fee aplikasi yang owner partner bayar ke
 * pemilik aplikasi, Rp per kemasan (mika) yang diantar operatornya. Hanya
 * dipakai untuk role 'owner'; null = 0 (owner lama/Ismi sendiri = 0, tak
 * perlu backfill). TIDAK mempengaruhi angka lama (omset/HPP/komisi/untung).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('app_fee_per_pack', 12, 2)->nullable()->after('komisi_per_mika_khusus');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('app_fee_per_pack');
        });
    }
};
