<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_returns', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('return_number', 60)->nullable()->unique();
            $table->unsignedInteger('order_id')->index();
            $table->unsignedInteger('user_id')->nullable()->index();
            $table->unsignedInteger('customer_id')->nullable()->index();
            $table->string('status', 20)->default('requested')->index();
            $table->string('return_type', 30)->default('customer_return')->index();
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('subtotal_amount', 20, 6)->nullable();
            $table->decimal('tax_amount', 20, 6)->nullable();
            $table->decimal('discount_amount', 20, 6)->nullable();
            $table->decimal('shipping_amount', 20, 6)->nullable();
            $table->decimal('total_amount', 20, 6)->nullable();
            $table->decimal('total_cost', 20, 6)->nullable();
            $table->decimal('profit_reversal_amount', 20, 6)->nullable();
            $table->timestamp('stock_reversed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('approved_by')->nullable()->index();
            $table->unsignedInteger('created_by')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('sales_return_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('sales_return_id')->index();
            $table->unsignedInteger('order_id')->index();
            $table->unsignedInteger('order_detail_id')->index();
            $table->unsignedInteger('product_id')->index();
            $table->unsignedInteger('product_stock_id')->nullable()->index();
            $table->string('variant')->nullable();
            $table->decimal('quantity', 20, 6);
            $table->decimal('unit_price', 20, 6)->nullable();
            $table->decimal('tax_amount', 20, 6)->nullable();
            $table->decimal('discount_amount', 20, 6)->nullable();
            $table->decimal('cost_price', 20, 6)->nullable();
            $table->decimal('total_cost', 20, 6)->nullable();
            $table->decimal('profit_reversal_amount', 20, 6)->nullable();
            $table->decimal('stock_reversed_quantity', 20, 6)->default(0);
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['sales_return_id', 'order_detail_id'], 'sales_return_item_detail_unique');
        });

        // A single order detail can have several partial returns. Keep sale
        // idempotency while moving reversal uniqueness to the return item.
        if ($this->indexExists('inventory_movements', 'inventory_movement_detail_type_unique')) {
            Schema::table('inventory_movements', function (Blueprint $table) {
                $table->dropUnique('inventory_movement_detail_type_unique');
            });
        }

        if (! $this->indexExists('inventory_movements', 'inventory_movement_reference_type_unique')) {
            Schema::table('inventory_movements', function (Blueprint $table) {
                $table->unique(['reference_type', 'reference_id', 'movement_type'], 'inventory_movement_reference_type_unique');
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists('inventory_movements', 'inventory_movement_reference_type_unique')) {
            Schema::table('inventory_movements', function (Blueprint $table) {
                $table->dropUnique('inventory_movement_reference_type_unique');
            });
        }

        Schema::dropIfExists('sales_return_items');
        Schema::dropIfExists('sales_returns');
    }

    private function indexExists(string $table, string $index): bool
    {
        return collect(DB::select("SHOW INDEX FROM `{$table}`"))
            ->contains(fn ($row) => ($row->Key_name ?? null) === $index);
    }
};
