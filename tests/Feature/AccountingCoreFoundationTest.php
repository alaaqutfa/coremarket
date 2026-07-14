<?php
namespace Tests\Feature;
use App\Models\AccountingEvent;
use App\Models\JournalEntry;
use App\Models\TaxRate;
use App\Services\AccountingPostingService;
use App\Services\TaxCalculationService;
use Database\Seeders\AccountingCoreSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AccountingCoreFoundationTest extends TestCase
{
    protected function setUp(): void { parent::setUp(); $this->ensureCoreSchema(); }
    public function test_chart_of_accounts_is_seeded_idempotently_and_journals_must_balance(): void
    {
        DB::beginTransaction(); try { $this->seed(AccountingCoreSeeder::class); $this->seed(AccountingCoreSeeder::class); $this->assertDatabaseCount('accounting_accounts', 12); $this->expectException(\DomainException::class); app(AccountingPostingService::class)->createBalancedJournalEntry($this->event('sale'), 'Invalid', [['1000',10,0]], 1); } finally { DB::rollBack(); }
    }
    public function test_sale_return_purchase_and_expense_events_post_balanced_journals_once(): void
    {
        DB::beginTransaction(); try { $this->seed(AccountingCoreSeeder::class); $service=app(AccountingPostingService::class); foreach (['sale','sale_return','purchase_receipt','expense'] as $type) { $event=$this->event($type, 10); $journal=$service->post($event, 1); $this->assertSame((float)$journal->total_debit,(float)$journal->total_credit); $this->assertSame($journal->id,$service->post($event,1)->id); } $this->assertDatabaseCount('journal_entries',4); } finally { DB::rollBack(); }
    }
    public function test_vat_calculations_and_snapshot_are_decimal_safe(): void
    {
        DB::beginTransaction(); try { $tax=app(TaxCalculationService::class); $this->assertSame(['taxable_amount'=>100.0,'tax_amount'=>11.0,'total_with_tax'=>111.0,'rate'=>11.0,'price_mode'=>'exclusive'],$tax->calculateExclusive(100,11)); $inclusive=$tax->calculateInclusive(111,11); $this->assertSame(100.0,$inclusive['taxable_amount']); $rate=TaxRate::create(['name'=>'VAT 11','code'=>'VAT11','rate'=>11,'price_mode'=>'exclusive']); $snapshot=$tax->createSnapshot($this->event('sale'),$rate,100,'USD'); $this->assertSame('11.000000',$snapshot->tax_amount); } finally { DB::rollBack(); }
    }
    public function test_accounting_and_vat_audits_are_read_only(): void
    {
        DB::beginTransaction(); try { $this->ensureCoreSchema(); $this->artisan('coremarket:accounting-core-audit')->assertSuccessful(); $this->artisan('coremarket:vat-audit')->assertSuccessful(); } finally { DB::rollBack(); }
    }
    private function event(string $type, float $tax=0): AccountingEvent { return AccountingEvent::create(['event_type'=>$type,'direction'=>'income','reference_type'=>'Tests\\'.$type,'reference_id'=>random_int(10000,999999),'amount'=>100,'cost_amount'=>40,'tax_amount'=>$tax,'profit_amount'=>60,'occurred_at'=>now(),'status'=>'posted']); }
    private function ensureCoreSchema(): void
    {
        if (!Schema::hasTable('accounting_accounts')) Schema::create('accounting_accounts', function (Blueprint $t) { $t->bigIncrements('id'); $t->string('code')->nullable()->unique(); $t->string('name'); $t->string('type'); $t->string('normal_balance'); $t->boolean('is_system')->default(false); $t->boolean('is_active')->default(true); $t->json('metadata')->nullable(); $t->timestamps(); });
        if (!Schema::hasTable('journal_entries')) Schema::create('journal_entries', function (Blueprint $t) { $t->bigIncrements('id'); $t->string('entry_number')->nullable()->unique(); $t->string('source_type')->nullable(); $t->unsignedBigInteger('source_id')->nullable(); $t->unsignedBigInteger('source_event_id')->nullable(); $t->string('status')->default('draft'); $t->date('entry_date'); $t->text('description')->nullable(); $t->string('currency')->nullable(); $t->decimal('total_debit',20,6)->default(0); $t->decimal('total_credit',20,6)->default(0); $t->timestamp('posted_at')->nullable(); $t->unsignedInteger('posted_by')->nullable(); $t->json('metadata')->nullable(); $t->timestamps(); $t->unique(['source_type','source_id']); });
        if (!Schema::hasTable('journal_entry_lines')) Schema::create('journal_entry_lines', function (Blueprint $t) { $t->bigIncrements('id'); $t->unsignedBigInteger('journal_entry_id'); $t->unsignedBigInteger('accounting_account_id'); $t->decimal('debit',20,6)->default(0); $t->decimal('credit',20,6)->default(0); $t->string('currency')->nullable(); $t->string('reference_type')->nullable(); $t->unsignedBigInteger('reference_id')->nullable(); $t->timestamps(); });
        if (!Schema::hasTable('tax_rates')) Schema::create('tax_rates', function (Blueprint $t) { $t->bigIncrements('id'); $t->string('name'); $t->string('code')->nullable()->unique(); $t->decimal('rate',8,4); $t->string('tax_type')->default('vat'); $t->string('calculation_type')->default('percentage'); $t->string('price_mode')->default('exclusive'); $t->boolean('is_active')->default(true); $t->date('starts_at')->nullable(); $t->date('ends_at')->nullable(); $t->json('metadata')->nullable(); $t->timestamps(); });
        if (!Schema::hasTable('tax_snapshots')) Schema::create('tax_snapshots', function (Blueprint $t) { $t->bigIncrements('id'); $t->string('source_type'); $t->unsignedBigInteger('source_id'); $t->unsignedBigInteger('tax_rate_id')->nullable(); $t->string('tax_name')->nullable(); $t->string('tax_code')->nullable(); $t->string('tax_type')->nullable(); $t->decimal('rate',8,4)->nullable(); $t->string('price_mode')->nullable(); $t->decimal('taxable_amount',20,6)->nullable(); $t->decimal('tax_amount',20,6)->nullable(); $t->decimal('total_with_tax',20,6)->nullable(); $t->string('currency')->nullable(); $t->json('metadata')->nullable(); $t->timestamps(); });
        if (Schema::hasTable('accounting_events') && !Schema::hasColumn('accounting_events','journal_entry_id')) Schema::table('accounting_events', function (Blueprint $t) { $t->unsignedBigInteger('journal_entry_id')->nullable(); $t->timestamp('journal_posted_at')->nullable(); $t->string('journal_posting_status')->nullable(); });
    }
}
