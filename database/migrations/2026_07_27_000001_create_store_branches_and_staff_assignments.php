<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_branches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 100)->nullable()->unique();
            $table->text('address')->nullable();
            $table->string('phone', 100)->nullable();
            // The legacy users primary key is INT UNSIGNED, not BIGINT.
            $table->unsignedInteger('manager_user_id')->nullable();
            $table->foreign('manager_user_id')->references('id')->on('users')->nullOnDelete();
            $table->boolean('is_default')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('staff_branch_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreignId('store_branch_id')->constrained('store_branches')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'store_branch_id'], 'staff_branch_user_unique');
            $table->index(['user_id', 'is_primary'], 'staff_branch_primary_index');
        });

        DB::table('store_branches')->insert([
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'is_default' => true,
            'is_active' => true,
            'metadata' => json_encode(['source' => 'coremarket_branch_foundation']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_branch_assignments');
        Schema::dropIfExists('store_branches');
    }
};
