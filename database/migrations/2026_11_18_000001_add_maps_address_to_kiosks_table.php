<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kiosks', function (Blueprint $table) {
            $table->text('maps_address')->nullable();
            $table->timestamp('maps_address_at')->nullable();
            $table->string('maps_address_source', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('kiosks', function (Blueprint $table) {
            $table->dropColumn(['maps_address', 'maps_address_at', 'maps_address_source']);
        });
    }
};
