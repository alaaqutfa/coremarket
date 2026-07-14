<?php

namespace Tests\Feature;

use App\Models\AccountingAccount;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\CoreMarketFeatureAccessService;
use App\Services\CoreMarketRuntimeSnapshotService;
use Database\Seeders\AccountingCoreSeeder;
use Database\Seeders\OperationsPermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\InteractsWithCoreMarketTestSchema;
use Tests\TestCase;

class AccountingUiTest extends TestCase
{
    use InteractsWithCoreMarketTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureBusinessSettingsTable();
        $this->ensurePermissionTables();
        $this->ensureLegacyUserColumns();
        config()->set('coremarket.runtime_snapshot.connection', 'mysql');
        DB::table('business_settings')->updateOrInsert(['type' => 'coremarket_runtime_features', 'lang' => null], ['value' => json_encode(['accounting_core' => true, 'accounting_lite' => true])]);
        Cache::forget('business_settings');
        app()->forgetInstance(CoreMarketFeatureAccessService::class);
        app()->forgetInstance(CoreMarketRuntimeSnapshotService::class);
        config()->set('coremarket.features.accounting_core', true);
        config()->set('coremarket.features.accounting_lite', true);
    }

    public function test_authorized_user_can_view_accounting_pages_and_post_a_balanced_draft(): void
    {
        DB::beginTransaction();
        try {
            $this->seed(AccountingCoreSeeder::class);
            $this->seed(OperationsPermissionSeeder::class);
            $user = $this->user(['accounting.core.view', 'accounting.accounts.view', 'accounting.journals.view', 'accounting.journals.post', 'accounting.events.view', 'accounting.general_ledger.view', 'accounting.trial_balance.view', 'accounting.profit_loss.view', 'accounting.tax.view', 'accounting.tax.audit'], 'admin');
            $this->assertTrue(app(CoreMarketFeatureAccessService::class)->enabled('accounting_core'));
            $cash = AccountingAccount::query()->where('code', '1000')->firstOrFail();
            $revenue = AccountingAccount::query()->where('code', '4000')->firstOrFail();
            $journal = JournalEntry::create(['status' => 'draft', 'entry_date' => now()->toDateString(), 'total_debit' => 20, 'total_credit' => 20, 'currency' => 'USD']);
            $journal->lines()->createMany([['accounting_account_id' => $cash->id, 'debit' => 20], ['accounting_account_id' => $revenue->id, 'credit' => 20]]);
            $this->actingAs($user)->get(route('operations.accounting.dashboard'))->assertOk()->assertSee('Accounting Dashboard');
            $this->actingAs($user)->get(route('operations.accounting.accounts'))->assertOk()->assertSee('Chart of Accounts');
            $this->actingAs($user)->get(route('operations.accounting.journals'))->assertOk()->assertSee('Journal Entries');
            $this->actingAs($user)->post(route('operations.accounting.journals.post', $journal))->assertRedirect();
            $this->assertSame('posted', $journal->fresh()->status);
            $this->actingAs($user)->get(route('operations.accounting.general-ledger'))->assertOk()->assertSee('General Ledger');
            $this->actingAs($user)->get(route('operations.accounting.trial-balance'))->assertOk()->assertSee('Trial Balance');
            $this->actingAs($user)->get(route('operations.accounting.profit-loss'))->assertOk()->assertSee('Profit & Loss');
            $this->actingAs($user)->get(route('operations.accounting.vat-audit'))->assertOk()->assertSee('VAT Audit');
        } finally { DB::rollBack(); }
    }

    public function test_unbalanced_draft_cannot_be_posted_and_core_feature_is_gated(): void
    {
        DB::beginTransaction();
        try {
            $this->seed(AccountingCoreSeeder::class);
            $this->seed(OperationsPermissionSeeder::class);
            $user = $this->user(['accounting.core.view', 'accounting.journals.post']);
            $journal = JournalEntry::create(['status' => 'draft', 'entry_date' => now()->toDateString(), 'total_debit' => 20, 'total_credit' => 10]);
            $this->actingAs($user)->post(route('operations.accounting.journals.post', $journal))->assertSessionHasErrors('journal');
            $this->assertSame('draft', $journal->fresh()->status);
            DB::table('business_settings')->where('type', 'coremarket_runtime_features')->whereNull('lang')->update(['value' => json_encode(['accounting_core' => false, 'accounting_lite' => false])]);
            app()->forgetInstance(CoreMarketFeatureAccessService::class);
            app()->forgetInstance(CoreMarketRuntimeSnapshotService::class);
            $this->actingAs($user)->get(route('operations.accounting.dashboard'))->assertNotFound();
        } finally { DB::rollBack(); }
    }

    private function user(array $permissions, string $userType = 'staff'): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::query()->firstOrCreate(['name' => 'Accounting UI '.uniqid(), 'guard_name' => 'web']);
        foreach ($permissions as $permission) $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        $user = new User();
        $user->forceFill(['name' => 'Accounting UI', 'email' => uniqid('accounting').'@example.test', 'password' => bcrypt('Temporary123!'), 'user_type' => $userType, 'email_verified_at' => now()])->save();
        $user->assignRole($role);
        return $user;
    }
}
