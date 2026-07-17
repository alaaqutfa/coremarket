<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('loyalty_accounts')) {
            Schema::create('loyalty_accounts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->unique();
                $table->unsignedInteger('points_balance')->default(0);
                $table->unsignedInteger('lifetime_points_earned')->default(0);
                $table->unsignedInteger('lifetime_points_redeemed')->default(0);
                $table->string('status')->default('active')->index();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('loyalty_rules')) {
            Schema::create('loyalty_rules', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->boolean('is_active')->default(true)->index();
                $table->decimal('earn_rate_amount', 20, 6)->default(1);
                $table->unsignedInteger('earn_rate_points')->default(1);
                $table->decimal('min_order_amount', 20, 6)->default(0);
                $table->string('currency')->nullable();
                $table->string('applies_to_order_from')->nullable()->index();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('loyalty_point_movements')) {
            Schema::create('loyalty_point_movements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('loyalty_account_id')->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('movement_type')->index();
                $table->string('direction')->index();
                $table->unsignedInteger('points');
                $table->unsignedInteger('balance_after');
                $table->string('reference_type')->nullable();
                $table->unsignedBigInteger('reference_id')->nullable();
                $table->string('idempotency_key')->nullable()->unique();
                $table->text('reason')->nullable();
                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->timestamp('expires_at')->nullable()->index();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['reference_type', 'reference_id']);
                $table->index('created_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_point_movements');
        Schema::dropIfExists('loyalty_rules');
        Schema::dropIfExists('loyalty_accounts');
    }
};
