<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kiosk_visits', function (Blueprint $table) {
            $table->string('alasan_check', 50)->nullable()->after('visit_action');
            $table->unsignedSmallInteger('sisa_biji')->nullable()->after('alasan_check');
        });
    }

    public function down(): void
    {
        Schema::table('kiosk_visits', function (Blueprint $table) {
            $table->dropColumn(['alasan_check', 'sisa_biji']);
        });
    }
};
