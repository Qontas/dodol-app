<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BRIEF 2 (21 Okt 2026) — Section 2. Tarif komisi Rian per mika EFEKTIF yang berlaku
 * di trip ini, diisi SEKALI saat trip dimulai (StartTrip) — bukan tarif owner/operator
 * SAAT INI yang dibaca ulang tiap kali laporan ditampilkan.
 *
 * KENAPA: Trip::getKomisiPerMika() selama ini baca $this->owner->komisi_per_mika LIVE
 * (tanpa snapshot apa pun) → trip yang SUDAH berakhir bulan lalu ikut berubah komisinya
 * kalau owner ganti tarif hari ini (dibuktikan dgn test kecil, lihat laporan investigasi
 * BRIEF 2 poin 1). Pola sama dgn deliveries.units_per_pack_snapshot/unit_price/
 * cost_snapshot (17 Okt 2026) — isi produk bisa berubah, tapi titipan lama harus pakai
 * nilai yang berlaku saat dititip.
 *
 * Nullable + backfill via `trips:backfill-komisi-snapshot` (command terpisah, dry-run
 * default) untuk trip lama. Kode kalkulasi (Trip::getKomisiPerMika() baca kolom ini)
 * BELUM diubah di migration/commit ini — itu "bagian hitungan" fase 2, ditahan sampai
 * fase 1 (kolom + backfill) sudah jalan & diverifikasi delta 0 di produksi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->decimal('komisi_per_mika_snapshot', 12, 2)->nullable()->after('qty_bs_redistributed');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn('komisi_per_mika_snapshot');
        });
    }
};
