<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kiosk_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')
                ->constrained('trips')
                ->cascadeOnDelete();
            $table->foreignId('kiosk_id')
                ->constrained('kiosks')
                ->restrictOnDelete();

            $table->timestamp('visited_at');
            $table->enum('visit_action', ['drop_and_settle', 'drop_only', 'check_only', 'settle_only']);

            $table->foreignId('new_delivery_id')->nullable()
                ->constrained('deliveries')
                ->nullOnDelete();
            $table->foreignId('settled_delivery_id')->nullable()
                ->constrained('deliveries')
                ->nullOnDelete();

            $table->boolean('extension_granted')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('visited_at');
            $table->index(['kiosk_id', 'visited_at'], 'idx_kiosk_last_visit');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kiosk_visits');
    }
};
