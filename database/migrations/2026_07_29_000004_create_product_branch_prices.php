<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_branch_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_branch_id')->constrained('store_branches')->cascadeOnDelete();
            $table->unsignedInteger('product_id')->index();
            // product_stocks.id is a signed INT in legacy CoreMarket baselines.
            $table->integer('product_stock_id')->index();
            $table->decimal('price', 20, 6);
            $table->decimal('compare_at_price', 20, 6)->nullable();
            $table->decimal('margin_percent', 12, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['store_branch_id', 'product_stock_id'],
                'product_branch_price_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_branch_prices');
    }
};
