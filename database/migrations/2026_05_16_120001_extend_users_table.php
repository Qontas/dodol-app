<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['owner', 'operator'])->default('owner')->after('password');
            $table->decimal('commission_rate', 5, 4)->nullable()->after('role');
            $table->boolean('is_active')->default(true)->after('commission_rate');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'commission_rate', 'is_active']);
        });
    }
};
