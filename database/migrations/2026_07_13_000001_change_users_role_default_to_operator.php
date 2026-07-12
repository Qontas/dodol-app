<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PERTAHANAN BERLAPIS (keamanan): ubah DEFAULT kolom `role` dari 'owner' → 'operator'.
 *
 * Sebelumnya kolom role DEFAULT 'owner'. Digabung dengan /register yang dulu PUBLIK
 * (sudah ditutup di routes/auth.php), siapa pun bisa membuat akun OWNER penuh. Route
 * register sudah dihapus, TAPI ini lapis kedua: kalau ADA jalur pembuatan user yang
 * lupa menyetel role eksplisit, hasilnya jadi 'operator' (hak paling kecil), BUKAN
 * owner. Pembuatan owner/super_admin harus SELALU menyetel role secara eksplisit.
 *
 * Baris user yang SUDAH ADA tidak diubah (default hanya berlaku untuk INSERT baru
 * tanpa nilai role). Enum tetap ('owner','operator','super_admin').
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('owner','operator','super_admin') NOT NULL DEFAULT 'operator'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('owner','operator','super_admin') NOT NULL DEFAULT 'owner'");
    }
};
