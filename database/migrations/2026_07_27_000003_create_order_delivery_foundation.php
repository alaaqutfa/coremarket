<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_deliveries', function (Blueprint $table) {
            $table->id();
            // Legacy orders use a signed INT while users use INT UNSIGNED.
            $table->integer('order_id')->unique();
            $table->unsignedInteger('delivery_user_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('status', 40)->default('pending_assignment')->index();
            $table->decimal('cod_amount', 20, 6)->nullable();
            $table->decimal('cod_collected_amount', 20, 6)->nullable();
            $table->string('cod_collection_status', 40)->default('not_required')->index();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('out_for_delivery_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('notes')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('delivery_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('branch_id')->references('id')->on('store_branches')->nullOnDelete();
            $table->index(['delivery_user_id', 'status'], 'order_deliveries_user_status_index');
            $table->index(['branch_id', 'status'], 'order_deliveries_branch_status_index');
        });

        Schema::create('order_delivery_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_delivery_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('old_status', 40)->nullable();
            $table->string('new_status', 40);
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('order_delivery_id')->references('id')->on('order_deliveries')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['order_delivery_id', 'created_at'], 'order_delivery_events_timeline_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_delivery_events');
        Schema::dropIfExists('order_deliveries');
    }
};
