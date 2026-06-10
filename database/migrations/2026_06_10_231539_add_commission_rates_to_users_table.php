<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('komisi_per_mika', 12, 2)->default(500.00)->after('harga_mika');
            $table->decimal('komisi_kios_baru_per_mika', 12, 2)->default(1000.00)->after('komisi_per_mika');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['komisi_per_mika', 'komisi_kios_baru_per_mika']);
        });
    }
};
