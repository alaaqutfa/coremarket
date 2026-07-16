<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashboxes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->nullable()->unique();
            $table->string('location')->nullable();
            $table->string('currency', 10)->nullable();
            $table->string('status')->default('active');
            $table->unsignedBigInteger('assigned_user_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('assigned_user_id');
        });

        Schema::create('cashier_shifts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cashbox_id');
            $table->unsignedBigInteger('opened_by');
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->string('status')->default('open');
            $table->dateTime('opened_at');
            $table->dateTime('closed_at')->nullable();
            $table->decimal('opening_balance', 20, 6)->default(0);
            $table->decimal('expected_cash', 20, 6)->default(0);
            $table->decimal('actual_cash', 20, 6)->nullable();
            $table->decimal('cash_difference', 20, 6)->nullable();
            $table->text('notes')->nullable();
            $table->text('close_notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['cashbox_id', 'status']);
            $table->index(['opened_by', 'status']);
            $table->index('opened_at');
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cashbox_id');
            $table->unsignedBigInteger('cashier_shift_id')->nullable();
            $table->string('movement_type');
            $table->string('direction');
            $table->decimal('amount', 20, 6);
            $table->string('currency', 10)->nullable();
            $table->text('description')->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->unsignedBigInteger('accounting_event_id')->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('occurred_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('cashbox_id');
            $table->index('cashier_shift_id');
            $table->index('movement_type');
            $table->index('occurred_at');
            $table->index(['cashbox_id', 'occurred_at']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cashier_shifts');
        Schema::dropIfExists('cashboxes');
    }
};
