<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->foreign('origin_settlement_id')
                ->references('id')->on('settlements')
                ->restrictOnDelete();

            $table->index('origin_settlement_id');
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropForeign(['origin_settlement_id']);
            $table->dropIndex(['origin_settlement_id']);
        });
    }
};
