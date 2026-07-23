<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_families')) {
            Schema::create('product_families', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('parent_id')->nullable()->index();
                $table->string('name', 191);
                $table->string('code', 100)->nullable()->unique();
                $table->string('level', 30)->default('family')->index();
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('products')) {
            if (! Schema::hasColumn('products', 'product_family_id')) {
                Schema::table('products', function (Blueprint $table) {
                    $table->unsignedBigInteger('product_family_id')->nullable()->index()->after('category_id');
                });
            }
            if (! Schema::hasColumn('products', 'product_sub_family_id')) {
                Schema::table('products', function (Blueprint $table) {
                    $table->unsignedBigInteger('product_sub_family_id')->nullable()->index()->after('product_family_id');
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'product_sub_family_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropIndex(['product_sub_family_id']);
                $table->dropColumn('product_sub_family_id');
            });
        }
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'product_family_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropIndex(['product_family_id']);
                $table->dropColumn('product_family_id');
            });
        }

        Schema::dropIfExists('product_families');
    }
};
