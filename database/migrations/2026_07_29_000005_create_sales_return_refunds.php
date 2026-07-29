<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_return_refunds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sales_return_id');
            $table->integer('order_id')->nullable();
            $table->unsignedInteger('customer_id')->nullable();
            $table->unsignedInteger('refunded_by_user_id');
            $table->unsignedBigInteger('cashbox_id')->nullable();
            $table->unsignedBigInteger('cashier_shift_id')->nullable();
            $table->unsignedBigInteger('cash_movement_id')->nullable();
            $table->unsignedBigInteger('customer_ledger_entry_id')->nullable();
            $table->string('refund_method', 40);
            $table->decimal('amount', 20, 6);
            $table->string('currency', 10)->nullable();
            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('sales_return_id')->references('id')->on('sales_returns')->restrictOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
            $table->foreign('customer_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('refunded_by_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('cashbox_id')->references('id')->on('cashboxes')->nullOnDelete();
            $table->foreign('cashier_shift_id')->references('id')->on('cashier_shifts')->nullOnDelete();
            $table->foreign('cash_movement_id')->references('id')->on('cash_movements')->nullOnDelete();
            $table->foreign('customer_ledger_entry_id')->references('id')->on('customer_ledger_entries')->nullOnDelete();
            $table->index(['sales_return_id', 'status']);
            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_return_refunds');
    }
};
