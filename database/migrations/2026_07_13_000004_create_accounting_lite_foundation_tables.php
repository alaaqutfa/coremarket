<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('accounting_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('event_type', 40)->index();
            $table->string('direction', 20)->index();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->unsignedInteger('order_id')->nullable()->index();
            $table->unsignedInteger('order_detail_id')->nullable()->index();
            $table->unsignedBigInteger('sales_return_id')->nullable()->index();
            $table->unsignedBigInteger('sales_return_item_id')->nullable()->index();
            $table->unsignedBigInteger('purchase_order_id')->nullable()->index();
            $table->unsignedBigInteger('purchase_receipt_id')->nullable()->index();
            $table->unsignedBigInteger('inventory_movement_id')->nullable()->index();
            $table->decimal('amount', 20, 6)->nullable();
            $table->decimal('cost_amount', 20, 6)->nullable();
            $table->decimal('tax_amount', 20, 6)->nullable();
            $table->decimal('profit_amount', 20, 6)->nullable();
            $table->string('currency', 10)->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->string('status', 20)->default('posted')->index();
            $table->json('metadata')->nullable();
            $table->unsignedInteger('created_by')->nullable()->index();
            $table->timestamps();
            $table->unique(['reference_type', 'reference_id', 'event_type'], 'accounting_event_reference_type_unique');
        });

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('code')->nullable()->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('expense_category_id')->nullable()->index();
            $table->string('title');
            $table->decimal('amount', 20, 6);
            $table->string('currency', 10)->nullable();
            $table->date('expense_date')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('vendor_name')->nullable();
            $table->string('reference_number')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->unsignedInteger('approved_by')->nullable()->index();
            $table->unsignedInteger('created_by')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('accounting_events');
    }
};
