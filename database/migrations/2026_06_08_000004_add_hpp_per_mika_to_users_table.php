<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // HPP per mika per owner. Default Rp 9.500 (flat) untuk semua owner baru.
            $table->decimal('hpp_per_mika', 10, 2)->default(9500)->after('commission_rate');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('hpp_per_mika');
        });
    }
};
