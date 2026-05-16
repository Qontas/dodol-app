<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->unique()
                ->constrained('deliveries')
                ->cascadeOnDelete();
            $table->date('visit_date');

            $table->smallInteger('qty_sold');
            $table->smallInteger('qty_returned_fresh')->default(0);
            $table->smallInteger('qty_returned_expired')->default(0);

            $table->decimal('amount_due', 12, 2);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->timestamp('paid_at')->nullable();

            $table->enum('status', ['pending', 'paid'])->default('pending');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('visit_date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlements');
    }
};
