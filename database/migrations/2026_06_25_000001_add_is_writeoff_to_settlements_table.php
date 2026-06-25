<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda settlement "kerugian / write-off": dibuat saat Stop Tanpa Tagih
 * (kedai kabur) — seluruh sisa titipan dianggap basi/hilang, uang Rp 0.
 * Dibedakan dari settlement tagih biasa yang kebetulan semua diretur basi,
 * supaya owner dashboard bisa menjumlahkan kerugian dengan andal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->boolean('is_writeoff')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->dropColumn('is_writeoff');
        });
    }
};
