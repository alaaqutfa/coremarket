<?php

namespace Tests\Feature;

use App\Models\CustomerAccountProfile;
use App\Models\CustomerLedgerEntry;
use App\Models\Order;
use App\Models\User;
use App\Services\CoreMarketCustomerCreditService;
use App\Services\CoreMarketCustomerReceivableService;
use App\Services\OperationsPdfService;
use Database\Seeders\DocumentTemplateSeeder;
use Database\Seeders\OperationsPermissionSeeder;
use Database\Seeders\StaffRolePresetSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CustomerCreditPolicyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('coremarket_testing', DB::getDatabaseName());
        $this->assertTrue(Schema::hasTable('customer_account_profiles'));
        $this->seed(OperationsPermissionSeeder::class);
        $this->seed(StaffRolePresetSeeder::class);
        $this->seed(DocumentTemplateSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_profile_is_created_on_demand_and_balance_comes_from_ledger(): void
    {
        $this->enableFeatures();
        $manager = $this->staff('credit-manager', 'owner_general_manager');
        $customer = $this->customer('credit-customer');
        $credit = app(CoreMarketCustomerCreditService::class);

        $this->assertSame(0, CustomerAccountProfile::count());
        $profile = $credit->updateProfile($customer, [
            'is_credit_allowed' => true,
            'credit_limit' => 500,
            'payment_terms_days' => 30,
            'account_status' => 'active',
        ], $manager);
        CustomerLedgerEntry::query()->create([
            'customer_id' => $customer->id,
            'entry_type' => 'opening_balance',
            'direction' => 'debit',
            'amount' => 125,
            'currency' => 'USD',
            'exchange_rate' => 1,
            'occurred_at' => now(),
            'idempotency_key' => 'credit-profile-balance-'.uniqid(),
            'created_by' => $manager->id,
        ]);

        $this->assertSame(125.0, $credit->currentBalance($customer));
        $this->assertSame(375.0, $credit->availableCredit($customer));
        $this->assertArrayNotHasKey('balance', $profile->getAttributes());
        $this->assertSame(1, CustomerAccountProfile::count());
    }

    public function test_credit_decision_blocks_disallowed_hold_blocked_and_over_limit_accounts(): void
    {
        $this->enableFeatures(paymentTerms: false);
        $manager = $this->staff('credit-decision-manager', 'owner_general_manager');
        $customer = $this->customer('credit-decision-customer');
        $credit = app(CoreMarketCustomerCreditService::class);

        $credit->updateProfile($customer, [
            'is_credit_allowed' => false,
            'credit_limit' => 100,
            'account_status' => 'active',
        ], $manager);
        $this->assertSame('credit_not_allowed', $credit->creditDecision($customer, 20)['reason']);

        foreach (['on_hold' => 'account_on_hold', 'blocked' => 'account_blocked'] as $status => $reason) {
            $credit->updateProfile($customer, [
                'is_credit_allowed' => true,
                'credit_limit' => 100,
                'account_status' => $status,
            ], $manager);
            $this->assertSame($reason, $credit->creditDecision($customer, 20)['reason']);
        }

        $credit->updateProfile($customer, [
            'is_credit_allowed' => true,
            'credit_limit' => 100,
            'account_status' => 'active',
        ], $manager);
        $this->assertTrue($credit->creditDecision($customer, 100)['allowed']);
        $this->assertSame('over_credit_limit', $credit->creditDecision($customer, 100.01)['reason']);
    }

    public function test_invoice_posting_snapshots_terms_and_due_date_without_changing_order_status(): void
    {
        $this->enableFeatures();
        $accountant = $this->staff('credit-terms-accountant', 'accountant');
        $customer = $this->customer('credit-terms-customer');
        app(CoreMarketCustomerCreditService::class)->updateProfile($customer, [
            'is_credit_allowed' => true,
            'credit_limit' => 1000,
            'payment_terms_days' => 30,
            'account_status' => 'active',
        ], $accountant);
        $order = $this->order($customer, 200);
        $entry = app(CoreMarketCustomerReceivableService::class)->createInvoiceEntryFromOrder($order, $accountant);

        $this->assertSame(30, $entry->metadata['payment_terms_days']);
        $this->assertSame($entry->occurred_at->copy()->addDays(30)->toDateString(), $entry->metadata['due_date']);
        $this->assertSame('1000.000000', $entry->metadata['credit_limit_snapshot']);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    public function test_overdue_invoice_blocks_new_credit_and_uses_due_date(): void
    {
        $this->enableFeatures();
        $manager = $this->staff('credit-overdue-manager', 'owner_general_manager');
        $customer = $this->customer('credit-overdue-customer');
        $credit = app(CoreMarketCustomerCreditService::class);
        $credit->updateProfile($customer, [
            'is_credit_allowed' => true,
            'credit_limit' => 1000,
            'payment_terms_days' => 10,
            'account_status' => 'active',
        ], $manager);
        $oldOrder = $this->order($customer, 80);
        $oldOrder->forceFill(['created_at' => now()->subDays(20), 'updated_at' => now()->subDays(20)])->save();
        app(CoreMarketCustomerReceivableService::class)->createInvoiceEntryFromOrder($oldOrder->fresh(), $manager);

        $this->assertSame(80.0, $credit->overdueBalance($customer));
        $this->assertSame('overdue_balance', $credit->creditDecision($customer, 20)['reason']);
        $this->assertSame(80.0, app(CoreMarketCustomerReceivableService::class)->agingSummary($customer)['1_30']);
    }

    public function test_disabled_policy_preserves_step_66_manual_posting_and_statement_fallback(): void
    {
        $manager = $this->staff('credit-disabled-manager', 'owner_general_manager');
        $customer = $this->customer('credit-disabled-customer');
        $this->setFeature('customer_accounts.enabled', true);
        $this->setFeature('customer_accounts.credit_limits_enabled', false);
        $this->setFeature('customer_accounts.payment_terms_enabled', false);

        $entry = app(CoreMarketCustomerReceivableService::class)
            ->createInvoiceEntryFromOrder($this->order($customer, 50), $manager);
        $this->assertNull($entry->metadata['due_date']);
        $this->assertFalse(app(OperationsPdfService::class)->customerStatement($customer)['isOperationalStatement']);

        $this->setFeature('customer_accounts.enabled', false);
        $this->assertTrue(app(OperationsPdfService::class)->customerStatement($customer)['isOperationalStatement']);
        $this->actingAs($manager)
            ->get(route('operations.customers.account-profile.show', $customer))
            ->assertNotFound();
    }

    public function test_profile_permissions_are_bounded_and_override_is_not_automatic(): void
    {
        $this->enableFeatures(paymentTerms: false);
        $owner = $this->staff('credit-owner', 'owner_general_manager');
        $accountant = $this->staff('credit-accountant', 'accountant');
        $cashier = $this->staff('credit-cashier', 'cashier');
        $driver = $this->staff('credit-driver', 'delivery_distribution');
        $customer = $this->customer('credit-permission-customer');

        $this->assertTrue($owner->can('customer_credit.override_limit'));
        $this->assertTrue($accountant->can('customer_credit.manage'));
        $this->assertFalse($accountant->can('customer_credit.override_limit'));
        $this->assertFalse($cashier->can('customer_credit.manage'));
        $this->assertFalse($driver->can('customer_credit.view'));

        $this->actingAs($accountant)
            ->put(route('operations.customers.account-profile.update', $customer), [
                'is_credit_allowed' => 1,
                'credit_limit' => 250,
                'payment_terms_days' => 15,
                'account_status' => 'active',
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('customer_account_profiles', [
            'customer_id' => $customer->id,
            'credit_limit' => 250,
        ]);
        $this->actingAs($cashier)
            ->get(route('operations.customers.account-profile.show', $customer))
            ->assertForbidden();
        $this->actingAs($driver)
            ->get(route('operations.customers.account-profile.show', $customer))
            ->assertForbidden();
    }

    public function test_no_historical_backfill_is_created(): void
    {
        $this->enableFeatures();
        $manager = $this->staff('credit-no-backfill-manager', 'owner_general_manager');
        $customer = $this->customer('credit-no-backfill-customer');
        $oldEntry = CustomerLedgerEntry::query()->create([
            'customer_id' => $customer->id,
            'entry_type' => 'invoice',
            'direction' => 'debit',
            'amount' => 40,
            'currency' => 'USD',
            'exchange_rate' => 1,
            'occurred_at' => now()->subMonth(),
            'idempotency_key' => 'credit-old-entry-'.uniqid(),
            'metadata' => ['source' => 'existing'],
            'created_by' => $manager->id,
        ]);

        app(CoreMarketCustomerCreditService::class)->updateProfile($customer, [
            'is_credit_allowed' => true,
            'credit_limit' => 500,
            'payment_terms_days' => 30,
            'account_status' => 'active',
        ], $manager);

        $this->assertSame(['source' => 'existing'], $oldEntry->fresh()->metadata);
        $this->assertSame(1, CustomerLedgerEntry::where('customer_id', $customer->id)->count());
    }

    private function enableFeatures(bool $paymentTerms = true): void
    {
        $this->setFeature('customer_accounts.enabled', true);
        $this->setFeature('customer_accounts.credit_limits_enabled', true);
        $this->setFeature('customer_accounts.payment_terms_enabled', $paymentTerms);
    }

    private function setFeature(string $key, bool $enabled): void
    {
        DB::table('business_settings')->updateOrInsert(
            ['type' => $key, 'lang' => null],
            ['value' => $enabled ? '1' : '0']
        );
        Cache::forget('business_settings');
    }

    private function order(User $customer, float $total): Order
    {
        $order = new Order();
        $order->forceFill([
            'user_id' => $customer->id,
            'shipping_address' => json_encode(['name' => $customer->name, 'city' => 'Beirut']),
            'shipping_type' => 'home_delivery',
            'order_from' => 'web',
            'delivery_status' => 'pending',
            'payment_type' => 'credit',
            'payment_status' => 'unpaid',
            'paid_amount' => 0,
            'grand_total' => $total,
            'code' => 'CREDIT-ORDER-'.uniqid(),
            'date' => time(),
            'viewed' => 0,
            'delivery_viewed' => 1,
            'commission_calculated' => 0,
            'notified' => 0,
        ])->save();

        return $order->fresh();
    }

    private function customer(string $prefix): User
    {
        $user = User::query()->create([
            'name' => ucwords(str_replace('-', ' ', $prefix)),
            'email' => $prefix.'-'.uniqid().'@example.test',
            'password' => bcrypt('Temporary123!'),
        ]);
        $user->forceFill(['user_type' => 'customer', 'phone' => '+961000000', 'banned' => 0])->save();

        return $user;
    }

    private function staff(string $prefix, string $role): User
    {
        $user = User::query()->create([
            'name' => ucwords(str_replace('_', ' ', $role)),
            'email' => $prefix.'-'.uniqid().'@example.test',
            'password' => bcrypt('Temporary123!'),
        ]);
        $user->forceFill(['user_type' => 'staff', 'banned' => 0])->save();
        $user->syncRoles($role);

        return $user->fresh();
    }
}
