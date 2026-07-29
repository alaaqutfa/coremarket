<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_adjustment_documents', function (Blueprint $table) {
            $table->id();
            $table->string('reference_no', 100)->nullable()->unique();
            $table->string('adjustment_type', 40)->index();
            $table->foreignId('branch_id')->nullable()->constrained('store_branches')->nullOnDelete();
            $table->string('status', 30)->default('draft')->index();
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->unsignedInteger('reviewed_by')->nullable();
            $table->unsignedInteger('posted_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->string('idempotency_key', 150)->nullable()->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('posted_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('inventory_adjustment_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('inventory_adjustment_document_id');
            $table->unsignedInteger('product_id');
            // Legacy CoreMarket baselines use a signed INT for product_stocks.id.
            // Keep the snapshot reference indexed without a cross-version FK.
            $table->integer('product_stock_id')->nullable()->index();
            $table->string('sku_snapshot')->nullable();
            $table->string('barcode_snapshot')->nullable();
            $table->string('product_name_snapshot')->nullable();
            $table->decimal('quantity_before', 20, 6)->default(0);
            $table->decimal('quantity_change', 20, 6);
            $table->decimal('quantity_after', 20, 6)->default(0);
            $table->decimal('unit_cost', 20, 6)->nullable();
            $table->decimal('amount', 20, 6)->nullable();
            $table->string('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('inventory_adjustment_document_id', 'inv_adj_item_document_fk')
                ->references('id')->on('inventory_adjustment_documents')->cascadeOnDelete();
            $table->index(['inventory_adjustment_document_id', 'product_id'], 'inventory_adjustment_item_product_idx');
        });

        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->string('reference_no', 100)->nullable()->unique();
            $table->foreignId('branch_id')->nullable()->constrained('store_branches')->nullOnDelete();
            $table->string('status', 30)->default('draft')->index();
            $table->unsignedInteger('counted_by')->nullable();
            $table->unsignedInteger('reviewed_by')->nullable();
            $table->unsignedInteger('posted_by')->nullable();
            $table->timestamp('counted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('counted_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('posted_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('stock_count_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained('stock_counts')->cascadeOnDelete();
            $table->unsignedInteger('product_id');
            $table->integer('product_stock_id')->nullable()->index();
            $table->string('sku_snapshot')->nullable();
            $table->string('barcode_snapshot')->nullable();
            $table->string('product_name_snapshot')->nullable();
            $table->decimal('expected_quantity', 20, 6);
            $table->decimal('counted_quantity', 20, 6);
            $table->decimal('variance_quantity', 20, 6);
            $table->decimal('unit_cost', 20, 6)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['stock_count_id', 'product_stock_id'], 'stock_count_item_stock_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_items');
        Schema::dropIfExists('stock_counts');
        Schema::dropIfExists('inventory_adjustment_items');
        Schema::dropIfExists('inventory_adjustment_documents');
    }
};
