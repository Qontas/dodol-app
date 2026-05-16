<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->date('trip_date');
            $table->smallInteger('trip_number_of_day')->default(1);
            $table->foreignId('operator_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->smallInteger('qty_carried_total')->default(0);

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('trip_date');
            $table->unique(['trip_date', 'trip_number_of_day'], 'idx_trip_date_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trips');
    }
};
