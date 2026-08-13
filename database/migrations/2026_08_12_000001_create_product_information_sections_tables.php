<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_information_sections', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('product_id');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['product_id', 'is_active', 'sort_order'], 'pis_product_active_order_idx');
            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->onDelete('cascade');
        });

        Schema::create('product_information_section_translations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('product_information_section_id');
            $table->string('lang', 100);
            $table->string('title', 255);
            $table->longText('content');
            $table->timestamps();

            $table->unique(['product_information_section_id', 'lang'], 'pist_section_lang_unique');
            $table->foreign('product_information_section_id', 'pist_section_fk')
                ->references('id')
                ->on('product_information_sections')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_information_section_translations');
        Schema::dropIfExists('product_information_sections');
    }
};
