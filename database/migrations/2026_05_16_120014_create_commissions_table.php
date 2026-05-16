<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')
                ->constrained('trips')
                ->cascadeOnDelete();
            $table->foreignId('operator_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->decimal('cash_collected_reported', 12, 2);
            $table->decimal('margin_rate_assumed', 5, 4)->default(0.3000);
            $table->decimal('commission_rate', 5, 4)->default(0.2000);
            $table->decimal('commission_amount', 12, 2)
                ->storedAs('cash_collected_reported * margin_rate_assumed * commission_rate');

            $table->enum('status', ['pending', 'paid'])->default('paid');
            $table->timestamp('paid_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('paid_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commissions');
    }
};
