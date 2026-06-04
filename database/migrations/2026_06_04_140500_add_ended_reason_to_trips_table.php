<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            // Alasan operator mengakhiri trip: stock_habis, target_done, sakit, urgent_personal, other
            $table->string('ended_reason')->nullable()->after('ended_at');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn('ended_reason');
        });
    }
};
