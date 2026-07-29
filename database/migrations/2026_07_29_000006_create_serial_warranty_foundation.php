<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('product_stocks', 'serial_tracking_enabled')) {
            Schema::table('product_stocks', function (Blueprint $table) {
                $table->boolean('serial_tracking_enabled')->default(false)->after('barcode');
            });
        }
        if (! Schema::hasColumn('product_stocks', 'imei_tracking_enabled')) {
            Schema::table('product_stocks', function (Blueprint $table) {
                $table->boolean('imei_tracking_enabled')->default(false)->after('serial_tracking_enabled');
            });
        }

        if (! Schema::hasTable('product_warranty_policies')) {
            Schema::create('product_warranty_policies', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('product_id')->nullable()->index();
            $table->unsignedInteger('product_stock_id')->nullable()->index();
            $table->string('name');
            $table->unsignedInteger('warranty_months')->default(0);
            $table->text('coverage_notes')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
            });
        }

        if (! Schema::hasTable('product_serial_units')) {
            Schema::create('product_serial_units', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('product_id')->index();
            $table->unsignedInteger('product_stock_id')->nullable()->index();
            $table->unsignedBigInteger('store_branch_id')->nullable()->index();
            $table->string('serial_number')->nullable()->unique();
            $table->string('imei_1')->nullable()->unique();
            $table->string('imei_2')->nullable()->unique();
            $table->string('barcode')->nullable()->index();
            $table->string('status', 30)->default('in_stock')->index();
            $table->unsignedBigInteger('purchase_order_id')->nullable()->index();
            $table->unsignedBigInteger('purchase_receipt_id')->nullable()->index();
            $table->integer('order_id')->nullable()->index();
            $table->integer('order_detail_id')->nullable()->index();
            $table->unsignedBigInteger('sales_return_id')->nullable()->index();
            $table->dateTime('warranty_expires_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            });
        }

        if (! Schema::hasTable('warranty_claims')) {
            Schema::create('warranty_claims', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('customer_id')->nullable()->index();
            $table->integer('order_id')->nullable()->index();
            $table->integer('order_detail_id')->nullable()->index();
            $table->unsignedBigInteger('product_serial_unit_id')->nullable()->index();
            $table->unsignedInteger('product_id')->index();
            $table->unsignedInteger('product_stock_id')->nullable()->index();
            $table->string('status', 30)->default('received')->index();
            $table->text('issue_description')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->unsignedInteger('received_by_user_id')->nullable();
            $table->unsignedInteger('closed_by_user_id')->nullable();
            $table->dateTime('received_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_claims');
        Schema::dropIfExists('product_serial_units');
        Schema::dropIfExists('product_warranty_policies');
        if (Schema::hasColumn('product_stocks', 'serial_tracking_enabled')) {
            Schema::table('product_stocks', function (Blueprint $table) {
                $table->dropColumn(['serial_tracking_enabled', 'imei_tracking_enabled']);
            });
        }
    }
};
