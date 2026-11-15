<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoice fee aplikasi per owner per bulan (super admin saja). Invoice TERBIT
 * disimpan sebagai snapshot beku (lines + issuer + billed_to) supaya PDF-nya tak
 * berubah walau data trip / pengaturan penerbit berubah. Draft tidak disimpan.
 *
 * "Satu invoice aktif (issued/paid) per owner+periode" dijaga di aplikasi
 * (AppFeeInvoiceService::issue: transaksi + lock baris owner), BUKAN lewat unique
 * index / generated column — pelajaran 11 Nov: generated column + FK cascade lolos di
 * MariaDB tapi ditolak MySQL 9.4 (error 1215).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_fee_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users');
            $table->string('period', 7); // YYYY-MM
            $table->string('number', 30)->unique();
            $table->string('status', 10)->default('issued'); // issued | paid | void
            $table->dateTime('issued_at');
            $table->date('due_date');
            $table->date('paid_at')->nullable();
            $table->string('paid_note', 255)->nullable();
            $table->dateTime('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->unsignedInteger('total_mika')->default(0);
            $table->decimal('total_fee', 14, 2)->default(0);
            $table->json('lines');
            $table->json('issuer');
            $table->json('billed_to');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['owner_id', 'period', 'status']);
            $table->index(['status', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_fee_invoices');
    }
};
