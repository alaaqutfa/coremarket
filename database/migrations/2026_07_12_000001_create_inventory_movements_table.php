<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('product_id')->index();
            $table->unsignedInteger('product_stock_id')->nullable()->index();
            $table->string('variant')->nullable();
            $table->string('movement_type', 40)->index();
            $table->string('direction', 10);
            $table->decimal('quantity', 20, 6);
            $table->decimal('unit_cost', 20, 6)->nullable();
            $table->decimal('total_cost', 20, 6)->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->unsignedInteger('order_id')->nullable()->index();
            $table->unsignedInteger('order_detail_id')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedInteger('created_by')->nullable()->index();
            $table->timestamps();

            $table->index(['reference_type', 'reference_id']);
            $table->unique(['order_detail_id', 'movement_type'], 'inventory_movement_detail_type_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
