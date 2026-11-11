<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE deliveries MODIFY source_type ENUM('new_procurement','fresh_return_redeploy','owner_placed','migration') NOT NULL DEFAULT 'new_procurement'");
        if (! Schema::hasColumn('deliveries', 'app_fee_per_pack_snapshot')) {
            Schema::table('deliveries', function (Blueprint $table) {
                $table->decimal('app_fee_per_pack_snapshot', 12, 2)->nullable();
            });
        }
    }

    public function down(): void
    {
        // Do not silently erase source provenance or turn a migration into billable stock.
        if (DB::table('deliveries')->whereIn('source_type', ['owner_placed', 'migration'])->exists()) {
            throw new RuntimeException('Cannot roll back while owner_placed/migration deliveries exist.');
        }
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn('app_fee_per_pack_snapshot');
        });
        DB::statement("ALTER TABLE deliveries MODIFY source_type ENUM('new_procurement','fresh_return_redeploy') NOT NULL DEFAULT 'new_procurement'");
    }
};
