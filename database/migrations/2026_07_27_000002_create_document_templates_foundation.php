<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('document_templates')) {
            return;
        }

        Schema::create('document_templates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 191);
            $table->string('code', 100)->nullable()->unique();
            $table->string('template_type', 40)->index();
            $table->string('paper_type', 30)->default('a4');
            $table->decimal('width_mm', 8, 2)->nullable();
            $table->decimal('height_mm', 8, 2)->nullable();
            $table->decimal('margin_top_mm', 8, 2)->nullable();
            $table->decimal('margin_right_mm', 8, 2)->nullable();
            $table->decimal('margin_bottom_mm', 8, 2)->nullable();
            $table->decimal('margin_left_mm', 8, 2)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->index(['template_type', 'is_active', 'is_default'], 'document_templates_resolution_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_templates');
    }
};
