<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Extend enum role: tambah 'super_admin' (existing: owner, operator).
        // Pakai raw ALTER MODIFY karena perubahan enum MySQL.
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('owner','operator','super_admin') NOT NULL DEFAULT 'owner'");

        // owner_id untuk operator: menunjuk ke owner-nya.
        // Super admin & owner: owner_id = null.
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('owner_id')->nullable()->after('role')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_id');
        });

        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('owner','operator') NOT NULL DEFAULT 'owner'");
    }
};
