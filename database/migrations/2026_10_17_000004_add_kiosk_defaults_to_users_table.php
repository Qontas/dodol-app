<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Default pengaturan kios baru PER OWNER (menu Settings owner) — sebelum ini
 * angka ideal/peringatan/batas-laris/durasi-kios-baru/jatah-titipan-awal hardcode
 * literal di KioskResource & CreateKiosk. Hanya memengaruhi kios BARU; kios yang
 * sudah ada tak berubah. Nullable, fallback di accessor User::get*Value().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->smallInteger('default_target_visit_interval_days')->nullable()->after('business_name');
            $table->smallInteger('default_warning_visit_interval_days')->nullable()->after('default_target_visit_interval_days');
            $table->unsignedTinyInteger('default_fast_mover_threshold_days')->nullable()->after('default_warning_visit_interval_days');
            $table->smallInteger('kios_baru_days')->nullable()->after('default_fast_mover_threshold_days');
            $table->smallInteger('default_qty_mika')->nullable()->after('kios_baru_days');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'default_target_visit_interval_days',
                'default_warning_visit_interval_days',
                'default_fast_mover_threshold_days',
                'kios_baru_days',
                'default_qty_mika',
            ]);
        });
    }
};
