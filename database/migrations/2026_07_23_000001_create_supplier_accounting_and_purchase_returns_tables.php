<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_ledger_entries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('supplier_id')->index();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('entry_type', 40)->index();
            $table->string('direction', 10);
            $table->decimal('amount', 20, 6);
            $table->string('currency', 10)->default('USD');
            $table->decimal('exchange_rate', 20, 6)->default(1);
            $table->decimal('amount_usd', 20, 6);
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->unsignedInteger('created_by')->nullable()->index();
            $table->timestamps();

            $table->index(['reference_type', 'reference_id']);
            $table->unique(
                ['reference_type', 'reference_id', 'entry_type'],
                'supplier_ledger_reference_entry_unique'
            );
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('payment_key', 100)->unique();
            $table->unsignedBigInteger('supplier_id')->index();
            $table->unsignedBigInteger('purchase_order_id')->nullable()->index();
            $table->decimal('amount', 20, 6);
            $table->string('currency', 10)->default('USD');
            $table->decimal('exchange_rate', 20, 6)->default(1);
            $table->decimal('amount_usd', 20, 6);
            $table->string('payment_method', 30)->nullable();
            $table->string('payment_reference')->nullable();
            $table->timestamp('paid_at');
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedInteger('created_by')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('return_number', 60)->nullable()->unique();
            $table->unsignedBigInteger('supplier_id')->index();
            $table->unsignedBigInteger('purchase_order_id')->nullable()->index();
            $table->string('status', 20)->default('draft')->index();
            $table->date('return_date');
            $table->string('currency', 10)->default('USD');
            $table->decimal('exchange_rate', 20, 6)->default(1);
            $table->decimal('subtotal', 20, 6)->default(0);
            $table->decimal('tax_total', 20, 6)->default(0);
            $table->decimal('total', 20, 6)->default(0);
            $table->decimal('total_usd', 20, 6)->default(0);
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedInteger('created_by')->nullable()->index();
            $table->unsignedInteger('completed_by')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('purchase_return_id')->index();
            $table->unsignedInteger('product_id')->nullable()->index();
            $table->unsignedInteger('product_stock_id')->nullable()->index();
            $table->unsignedBigInteger('purchase_order_item_id')->nullable()->index();
            $table->decimal('quantity', 20, 6);
            $table->decimal('unit_cost', 20, 6);
            $table->decimal('tax_amount', 20, 6)->default(0);
            $table->decimal('line_total', 20, 6);
            $table->decimal('stock_returned_quantity', 20, 6)->default(0);
            $table->unsignedBigInteger('inventory_movement_id')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['purchase_return_id', 'purchase_order_item_id'],
                'purchase_return_order_item_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('supplier_ledger_entries');
    }
};
