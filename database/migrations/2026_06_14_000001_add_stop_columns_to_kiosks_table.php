<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kiosks', function (Blueprint $table) {
            // Cut off / stop titipan. is_active=false menandai kios stop;
            // kolom ini menyimpan jejak kapan, alasan, & siapa yang stop.
            $table->timestamp('stopped_at')->nullable()->after('is_active');
            $table->string('stop_reason')->nullable()->after('stopped_at');
            $table->string('stopped_by')->nullable()->after('stop_reason'); // 'operator' | 'owner'
        });
    }

    public function down(): void
    {
        Schema::table('kiosks', function (Blueprint $table) {
            $table->dropColumn(['stopped_at', 'stop_reason', 'stopped_by']);
        });
    }
};
