<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('price_lists')) {
            Schema::create('price_lists', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name', 191);
                $table->string('code', 100)->unique();
                $table->string('type', 30)->default('custom');
                $table->string('pricing_method', 40)->default('fixed_price');
                $table->decimal('margin_percent', 8, 4)->nullable();
                $table->decimal('discount_percent', 8, 4)->nullable();
                $table->string('currency', 10)->default('USD');
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('price_list_items')) {
            Schema::create('price_list_items', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('price_list_id');
                $table->unsignedInteger('product_id')->nullable();
                $table->unsignedInteger('product_stock_id')->nullable();
                $table->decimal('fixed_price', 20, 6)->nullable();
                $table->decimal('margin_percent', 8, 4)->nullable();
                $table->decimal('discount_percent', 8, 4)->nullable();
                $table->dateTime('starts_at')->nullable();
                $table->dateTime('ends_at')->nullable();
                $table->boolean('is_active')->default(true);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['price_list_id', 'product_id']);
                $table->index(['price_list_id', 'product_stock_id']);
            });
        }

        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'price_list_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('price_list_id')->nullable()->index()->after('customer_package_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'price_list_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropIndex(['price_list_id']);
                $table->dropColumn('price_list_id');
            });
        }
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_lists');
    }
};
