<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_cod_settlements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_delivery_id');
            $table->integer('order_id');
            $table->unsignedInteger('delivery_user_id')->nullable();
            $table->unsignedInteger('received_by_user_id');
            $table->unsignedBigInteger('cashbox_id')->nullable();
            $table->unsignedBigInteger('cashier_shift_id')->nullable();
            $table->unsignedBigInteger('cash_movement_id')->nullable();
            $table->decimal('amount', 20, 6);
            $table->string('currency', 10)->nullable();
            $table->string('status', 40)->default('posted');
            $table->string('idempotency_key', 64)->unique();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('order_delivery_id')->references('id')->on('order_deliveries')->cascadeOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('delivery_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('received_by_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('cashbox_id')->references('id')->on('cashboxes')->nullOnDelete();
            $table->foreign('cashier_shift_id')->references('id')->on('cashier_shifts')->nullOnDelete();
            $table->foreign('cash_movement_id')->references('id')->on('cash_movements')->nullOnDelete();
            $table->index(['order_delivery_id', 'status'], 'delivery_cod_settlements_delivery_status_index');
            $table->index(['delivery_user_id', 'created_at'], 'delivery_cod_settlements_driver_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_cod_settlements');
    }
};
