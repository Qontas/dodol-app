<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel Level 1 (root) yang butuh owner_id langsung.
     * Level 2 (kiosks, product_variants, deliveries, settlements, kiosk_visits,
     * commissions) owner-nya diketahui lewat parent — TIDAK perlu kolom.
     */
    private array $tables = ['clusters', 'suppliers', 'products', 'procurement_batches', 'trips'];

    public function up(): void
    {
        foreach ($this->tables as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->foreignId('owner_id')->nullable()->after('id')
                    ->constrained('users')->cascadeOnDelete();
            });
        }

        // Backfill data existing → owner_id = owner pertama (kalau ada).
        // Saat fresh migrate (test/dev awal) tabel users kosong → no-op aman.
        $ownerId = DB::table('users')->where('role', 'owner')->orderBy('id')->value('id');

        if ($ownerId) {
            foreach ($this->tables as $t) {
                DB::table($t)->whereNull('owner_id')->update(['owner_id' => $ownerId]);
            }

            // Operator existing terikat ke owner pertama.
            DB::table('users')->where('role', 'operator')->whereNull('owner_id')
                ->update(['owner_id' => $ownerId]);
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->dropConstrainedForeignId('owner_id');
            });
        }
    }
};
