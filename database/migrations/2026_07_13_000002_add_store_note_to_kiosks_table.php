<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Catatan Kedai" — teks bebas karakteristik kedai yang bisa diisi owner ATAU operator
 * dan TAMPIL MENONJOL saat operator buka kedai (biar operator tahu kebiasaan kedai tanpa
 * harus ingat). Contoh: "Cash-only, biasa minta 5 mika" / "Suka-suka, kadang banyak".
 *
 * Kolom TERPISAH dari `notes` yang dipakai jejak audit sistem (input lapangan operator,
 * jejak ganti foto) — biar catatan manusiawi tidak tercampur log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kiosks', function (Blueprint $table) {
            $table->string('store_note', 500)->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('kiosks', function (Blueprint $table) {
            $table->dropColumn('store_note');
        });
    }
};
