<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_account_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('customer_id')->unique();
            $table->boolean('is_credit_allowed')->default(false);
            $table->decimal('credit_limit', 20, 6)->nullable();
            $table->string('credit_limit_currency', 10)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->string('account_status', 20)->default('active');
            $table->string('default_payment_method', 30)->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('last_reviewed_at')->nullable();
            $table->unsignedInteger('reviewed_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('reviewed_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['account_status', 'is_credit_allowed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_account_profiles');
    }
};
