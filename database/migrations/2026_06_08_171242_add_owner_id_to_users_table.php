<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            try {
                $table->dropForeign(['owner_id']);
            } catch (\Throwable $e) {
                // Ignore if it doesn't exist
            }
            $table->foreign('owner_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            try {
                $table->dropForeign(['owner_id']);
            } catch (\Throwable $e) {
                // Ignore
            }
            $table->foreign('owner_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }
};
