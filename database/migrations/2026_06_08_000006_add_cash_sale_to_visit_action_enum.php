<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Tambah 'cash_sale' ke enum visit_action (existing: 4 aksi).
        DB::statement("ALTER TABLE kiosk_visits MODIFY visit_action ENUM('drop_and_settle','drop_only','settle_only','check_only','cash_sale') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE kiosk_visits MODIFY visit_action ENUM('drop_and_settle','drop_only','settle_only','check_only') NOT NULL");
    }
};
