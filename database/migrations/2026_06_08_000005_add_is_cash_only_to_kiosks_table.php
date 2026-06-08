<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kiosks', function (Blueprint $table) {
            // Kios cash only: setiap drop langsung bayar cash, tidak ada konsinyasi.
            $table->boolean('is_cash_only')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('kiosks', function (Blueprint $table) {
            $table->dropColumn('is_cash_only');
        });
    }
};
