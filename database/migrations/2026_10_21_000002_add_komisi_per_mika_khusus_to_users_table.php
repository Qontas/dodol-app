<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BRIEF 2 (21 Okt 2026) — Section 3. Tarif komisi KHUSUS untuk satu operator,
 * nullable — kosong = ikut tarif default owner (komisi_per_mika). Hanya SATU
 * kolom, bukan dua (reguler + kios baru): investigasi Section 1 membuktikan
 * split "komisi kios baru" sudah mati sejak sebelum BRIEF 2 ini — Trip::
 * getKomisiKiosBaruAttribute() hardcode 0.0, komisi kini flat satu tarif
 * (lihat laporan investigasi). Membangun dua kolom untuk perhitungan yang
 * sudah tidak membedakan keduanya cuma menambah kebingungan, bukan kebenaran.
 *
 * Kolom ini AMAN ditambahkan sekarang (additive, nullable, selalu NULL untuk
 * semua operator sampai owner mengisinya lewat form) — tidak ada kode yang
 * membacanya di commit ini, jadi tidak mengubah angka apa pun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('komisi_per_mika_khusus', 12, 2)->nullable()->after('komisi_kios_baru_per_mika');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('komisi_per_mika_khusus');
        });
    }
};
