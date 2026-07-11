<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Komisi Rian pindah ke basis DROP (Opsi Y): Rp 1.000 per mika yang Rian
 * BAWA & DILETAKKAN (exclude BS daur-ulang). Tarif default per mika naik dari
 * Rp 500 → Rp 1.000 supaya owner/tenant baru langsung pakai tarif final.
 *
 * CATATAN SENGAJA: migration ini HANYA mengubah DEFAULT kolom (berlaku untuk
 * baris BARU). Baris owner yang SUDAH ADA TIDAK diubah — perubahan tarif gaji
 * operator harus keputusan owner eksplisit lewat /owner/settings (bukan diam-
 * diam via migration). Owner lama yang masih Rp 500 tinggal set 1.000 di sana.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('komisi_per_mika', 12, 2)->default(1000.00)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('komisi_per_mika', 12, 2)->default(500.00)->change();
        });
    }
};
