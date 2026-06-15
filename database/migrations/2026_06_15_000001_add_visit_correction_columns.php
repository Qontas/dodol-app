<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Koreksi angka visit (reversal) — lihat PROMPT_KOREKSI_VISIT_2.
 *
 * - kiosk_visits.corrected_at        : penanda visit sudah dikoreksi (audit trail,
 *                                       baris lama disimpan, dikeluarkan dari agregat
 *                                       via scope KioskVisit::active()).
 * - kiosk_visits.correction_of_visit_id : visit koreksi menunjuk visit yang dikoreksi.
 * - kiosk_visits.changed_default     : true bila visit mengubah default_qty_mika kios
 *                                       (turunkan default / konsinyasi penuh). Visit
 *                                       jenis ini DILARANG dikoreksi (keputusan C) —
 *                                       perubahan default tidak punya nilai-lama untuk
 *                                       di-revert, jadi ditandai saat simpan.
 * - deliveries.kiosk_visit_id        : linkage deterministik; semua delivery yang dibuat
 *                                       1 visit menunjuk ke visit itu, agar reversal
 *                                       menemukan seluruh record satu visit secara pasti.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kiosk_visits', function (Blueprint $table) {
            $table->timestamp('corrected_at')->nullable()->after('extension_granted');
            $table->foreignId('correction_of_visit_id')->nullable()->after('corrected_at')
                ->constrained('kiosk_visits')->nullOnDelete();
            $table->boolean('changed_default')->default(false)->after('correction_of_visit_id');
        });

        Schema::table('deliveries', function (Blueprint $table) {
            $table->foreignId('kiosk_visit_id')->nullable()->after('trip_id')
                ->constrained('kiosk_visits')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kiosk_visit_id');
        });

        Schema::table('kiosk_visits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('correction_of_visit_id');
            $table->dropColumn(['corrected_at', 'changed_default']);
        });
    }
};
