<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fuel_logs', function (Blueprint $table) {
            $table->id();
            $table->date('week_start');
            $table->date('week_end');
            $table->decimal('total_fuel_cost', 12, 2);
            $table->smallInteger('trip_count_snapshot');
            $table->decimal('cost_per_trip', 12, 2)
                ->storedAs('total_fuel_cost / NULLIF(trip_count_snapshot, 0)');
            $table->foreignId('operator_id')->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['week_start', 'week_end'], 'idx_fuel_week');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fuel_logs');
    }
};
