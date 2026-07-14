<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('accounting_accounts', function (Blueprint $table) {
            $table->bigIncrements('id'); $table->string('code')->nullable()->unique(); $table->string('name');
            $table->string('type', 30)->index(); $table->string('normal_balance', 10); $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->boolean('is_system')->default(false); $table->boolean('is_active')->default(true); $table->json('metadata')->nullable(); $table->timestamps();
        });
        Schema::create('accounting_fiscal_periods', function (Blueprint $table) {
            $table->bigIncrements('id'); $table->string('name'); $table->date('starts_at'); $table->date('ends_at'); $table->string('status', 20)->default('open')->index(); $table->json('metadata')->nullable(); $table->timestamps();
        });
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->bigIncrements('id'); $table->string('entry_number')->nullable()->unique(); $table->string('source_type')->nullable(); $table->unsignedBigInteger('source_id')->nullable(); $table->unsignedBigInteger('source_event_id')->nullable()->index(); $table->unsignedBigInteger('fiscal_period_id')->nullable()->index();
            $table->string('status', 20)->default('draft')->index(); $table->date('entry_date'); $table->text('description')->nullable(); $table->string('currency', 10)->nullable(); $table->decimal('total_debit', 20, 6)->default(0); $table->decimal('total_credit', 20, 6)->default(0); $table->timestamp('posted_at')->nullable(); $table->unsignedInteger('posted_by')->nullable()->index(); $table->json('metadata')->nullable(); $table->timestamps();
            $table->unique(['source_type', 'source_id'], 'journal_entry_source_unique');
        });
        Schema::create('journal_entry_lines', function (Blueprint $table) {
            $table->bigIncrements('id'); $table->unsignedBigInteger('journal_entry_id')->index(); $table->unsignedBigInteger('accounting_account_id')->index(); $table->text('description')->nullable(); $table->decimal('debit', 20, 6)->default(0); $table->decimal('credit', 20, 6)->default(0); $table->string('currency', 10)->nullable(); $table->string('reference_type')->nullable(); $table->unsignedBigInteger('reference_id')->nullable(); $table->unsignedInteger('product_id')->nullable()->index(); $table->unsignedBigInteger('product_stock_id')->nullable()->index(); $table->unsignedBigInteger('tax_rate_id')->nullable()->index(); $table->json('metadata')->nullable(); $table->timestamps();
        });
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->bigIncrements('id'); $table->string('name'); $table->string('code')->nullable()->unique(); $table->decimal('rate', 8, 4); $table->string('tax_type', 20)->default('vat'); $table->string('calculation_type', 20)->default('percentage'); $table->string('price_mode', 20)->default('exclusive'); $table->boolean('is_active')->default(true); $table->date('starts_at')->nullable(); $table->date('ends_at')->nullable(); $table->json('metadata')->nullable(); $table->timestamps();
        });
        Schema::create('tax_snapshots', function (Blueprint $table) {
            $table->bigIncrements('id'); $table->string('source_type'); $table->unsignedBigInteger('source_id'); $table->unsignedBigInteger('tax_rate_id')->nullable()->index(); $table->string('tax_name')->nullable(); $table->string('tax_code')->nullable(); $table->string('tax_type', 20)->nullable(); $table->decimal('rate', 8, 4)->nullable(); $table->string('price_mode', 20)->nullable(); $table->decimal('taxable_amount', 20, 6)->nullable(); $table->decimal('tax_amount', 20, 6)->nullable(); $table->decimal('total_with_tax', 20, 6)->nullable(); $table->string('currency', 10)->nullable(); $table->json('metadata')->nullable(); $table->timestamps();
            $table->index(['source_type', 'source_id']);
        });
        Schema::table('accounting_events', function (Blueprint $table) {
            $table->unsignedBigInteger('journal_entry_id')->nullable()->index()->after('inventory_movement_id'); $table->timestamp('journal_posted_at')->nullable()->after('posted_at'); $table->string('journal_posting_status', 20)->nullable()->index()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('accounting_events', function (Blueprint $table) { $table->dropColumn(['journal_entry_id', 'journal_posted_at', 'journal_posting_status']); });
        Schema::dropIfExists('tax_snapshots'); Schema::dropIfExists('tax_rates'); Schema::dropIfExists('journal_entry_lines'); Schema::dropIfExists('journal_entries'); Schema::dropIfExists('accounting_fiscal_periods'); Schema::dropIfExists('accounting_accounts');
    }
};
