<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kiosks', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('owner_name')->nullable();
            $table->string('phone', 20)->nullable();
            $table->foreignId('cluster_id')->nullable()
                ->constrained('clusters')
                ->restrictOnDelete();

            $table->smallInteger('target_visit_interval_days')->default(14);
            $table->smallInteger('warning_visit_interval_days')->default(10);

            $table->text('location_description')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('photo_path', 500)->nullable();

            $table->smallInteger('default_qty_mika')->nullable();
            $table->date('first_titip_date')->nullable();

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
            $table->index(['latitude', 'longitude'], 'idx_kiosks_geo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kiosks');
    }
};
