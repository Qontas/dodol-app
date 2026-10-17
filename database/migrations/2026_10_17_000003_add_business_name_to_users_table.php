<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nama bisnis per owner (header panel/laporan/export) — sebelum ini layout/export
 * memakai `users.name` (nama ORANG owner) sebagai pengganti nama bisnis. Nullable,
 * fallback "Cemilan Qontas" di User::getBusinessNameValue().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('business_name')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('business_name');
        });
    }
};
