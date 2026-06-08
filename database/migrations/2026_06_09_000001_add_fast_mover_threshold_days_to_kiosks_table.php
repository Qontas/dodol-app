<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kiosks', function (Blueprint $table) {
            $table->unsignedTinyInteger('fast_mover_threshold_days')
                ->nullable()
                ->after('warning_visit_interval_days')
                ->comment('Threshold hari untuk flag fast mover. Null = tidak dimonitor.');
        });
    }

    public function down(): void
    {
        Schema::table('kiosks', function (Blueprint $table) {
            $table->dropColumn('fast_mover_threshold_days');
        });
    }
};
