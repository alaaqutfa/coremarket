<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('company_name')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('tax_number')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('purchase_number', 60)->nullable()->unique();
            $table->unsignedBigInteger('supplier_id')->nullable()->index();
            $table->string('status', 30)->default('draft')->index();
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('currency', 10)->nullable();
            $table->decimal('subtotal_amount', 20, 6)->nullable();
            $table->decimal('tax_amount', 20, 6)->nullable();
            $table->decimal('discount_amount', 20, 6)->nullable();
            $table->decimal('shipping_amount', 20, 6)->nullable();
            $table->decimal('total_amount', 20, 6)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('created_by')->nullable()->index();
            $table->unsignedInteger('received_by')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('purchase_order_id')->index();
            $table->unsignedInteger('product_id')->index();
            $table->unsignedInteger('product_stock_id')->nullable()->index();
            $table->string('variant')->nullable();
            $table->decimal('quantity_ordered', 20, 6);
            $table->decimal('quantity_received', 20, 6)->default(0);
            $table->decimal('unit_cost', 20, 6)->nullable();
            $table->decimal('tax_amount', 20, 6)->nullable();
            $table->decimal('discount_amount', 20, 6)->nullable();
            $table->decimal('total_cost', 20, 6)->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        // Receipt rows give partial receiving a durable idempotency boundary.
        Schema::create('purchase_receipts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('purchase_order_id')->index();
            $table->string('receipt_key', 100)->unique();
            $table->timestamp('received_at');
            $table->unsignedInteger('received_by')->nullable()->index();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_receipt_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('purchase_receipt_id')->index();
            $table->unsignedBigInteger('purchase_order_item_id')->index();
            $table->unsignedInteger('product_id')->index();
            $table->unsignedInteger('product_stock_id')->nullable()->index();
            $table->decimal('quantity_received', 20, 6);
            $table->decimal('unit_cost', 20, 6)->nullable();
            $table->decimal('total_cost', 20, 6)->nullable();
            $table->unsignedBigInteger('inventory_movement_id')->nullable()->index();
            $table->timestamps();

            $table->unique(['purchase_receipt_id', 'purchase_order_item_id'], 'purchase_receipt_item_order_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_receipt_items');
        Schema::dropIfExists('purchase_receipts');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('suppliers');
    }
};
