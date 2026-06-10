<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Tambah 'bs_redistribution' ke enum delivery_type (existing: consignment, cash_sale).
        DB::statement("ALTER TABLE deliveries MODIFY delivery_type ENUM('consignment','cash_sale','bs_redistribution') NOT NULL DEFAULT 'consignment'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE deliveries MODIFY delivery_type ENUM('consignment','cash_sale') NOT NULL DEFAULT 'consignment'");
    }
};
