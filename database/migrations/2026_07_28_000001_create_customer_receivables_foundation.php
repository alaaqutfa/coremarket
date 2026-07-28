<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('customer_id');
            $table->unsignedInteger('received_by_user_id');
            $table->unsignedBigInteger('cashbox_id')->nullable();
            $table->unsignedBigInteger('cashier_shift_id')->nullable();
            $table->unsignedBigInteger('cash_movement_id')->nullable();
            $table->decimal('amount', 20, 6);
            $table->string('currency', 10)->nullable();
            $table->string('payment_method', 30);
            $table->string('reference')->nullable();
            $table->string('status', 20)->default('posted');
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('received_by_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('cashbox_id')->references('id')->on('cashboxes')->nullOnDelete();
            $table->foreign('cashier_shift_id')->references('id')->on('cashier_shifts')->nullOnDelete();
            $table->foreign('cash_movement_id')->references('id')->on('cash_movements')->nullOnDelete();
            $table->index(['customer_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('customer_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('customer_id');
            $table->integer('order_id')->nullable();
            $table->unsignedBigInteger('sales_return_id')->nullable();
            $table->unsignedBigInteger('customer_payment_id')->nullable();
            $table->string('entry_type', 40);
            $table->string('direction', 10);
            $table->decimal('amount', 20, 6);
            $table->string('currency', 10)->nullable();
            $table->decimal('exchange_rate', 20, 6)->nullable();
            $table->timestamp('occurred_at');
            $table->text('description')->nullable();
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->json('metadata')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
            $table->foreign('sales_return_id')->references('id')->on('sales_returns')->nullOnDelete();
            $table->foreign('customer_payment_id')->references('id')->on('customer_payments')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['customer_id', 'occurred_at']);
            $table->index(['order_id', 'entry_type']);
            $table->index(['sales_return_id', 'entry_type']);
        });

        Schema::create('customer_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_payment_id');
            $table->unsignedBigInteger('customer_ledger_entry_id')->nullable();
            $table->integer('order_id')->nullable();
            $table->decimal('amount', 20, 6);
            $table->timestamps();

            $table->foreign('customer_payment_id')->references('id')->on('customer_payments')->cascadeOnDelete();
            $table->foreign('customer_ledger_entry_id')->references('id')->on('customer_ledger_entries')->nullOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
            $table->unique(
                ['customer_payment_id', 'customer_ledger_entry_id'],
                'customer_payment_allocation_entry_unique'
            );
            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_payment_allocations');
        Schema::dropIfExists('customer_ledger_entries');
        Schema::dropIfExists('customer_payments');
    }
};
