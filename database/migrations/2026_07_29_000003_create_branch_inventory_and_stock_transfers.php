<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_stock_branch_balances', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('product_id')->index();
            // product_stocks.id is a signed INT in legacy CoreMarket baselines.
            $table->integer('product_stock_id')->index();
            $table->foreignId('store_branch_id')->constrained('store_branches')->cascadeOnDelete();
            $table->decimal('quantity', 20, 6)->default(0);
            $table->decimal('reserved_quantity', 20, 6)->default(0);
            $table->timestamp('last_movement_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['product_stock_id', 'store_branch_id'],
                'product_stock_branch_unique'
            );
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('reference_no', 100)->nullable()->unique();
            $table->foreignId('from_branch_id')->constrained('store_branches');
            $table->foreignId('to_branch_id')->constrained('store_branches');
            $table->string('status', 30)->default('draft')->index();
            $table->unsignedInteger('requested_by')->nullable();
            $table->unsignedInteger('approved_by')->nullable();
            $table->unsignedInteger('shipped_by')->nullable();
            $table->unsignedInteger('received_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('idempotency_key', 150)->nullable()->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('shipped_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('received_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $table->unsignedInteger('product_id')->index();
            $table->integer('product_stock_id')->index();
            $table->string('sku_snapshot')->nullable();
            $table->string('barcode_snapshot')->nullable();
            $table->string('product_name_snapshot')->nullable();
            $table->decimal('quantity', 20, 6);
            $table->decimal('quantity_shipped', 20, 6)->nullable();
            $table->decimal('quantity_received', 20, 6)->nullable();
            $table->decimal('unit_cost', 20, 6)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['stock_transfer_id', 'product_stock_id'],
                'stock_transfer_item_stock_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('product_stock_branch_balances');
    }
};
