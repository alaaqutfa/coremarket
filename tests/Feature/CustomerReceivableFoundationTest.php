<?php

namespace Tests\Feature;

use App\Models\CashMovement;
use App\Models\CustomerLedgerEntry;
use App\Models\CustomerPayment;
use App\Models\Order;
use App\Models\User;
use App\Services\CashboxService;
use App\Services\CoreMarketCustomerReceivableService;
use App\Services\OperationsPdfService;
use Database\Seeders\DocumentTemplateSeeder;
use Database\Seeders\OperationsPermissionSeeder;
use Database\Seeders\StaffRolePresetSeeder;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CustomerReceivableFoundationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('coremarket_testing', DB::getDatabaseName());
        $this->assertTrue(Schema::hasTable('customer_ledger_entries'));
        $this->seed(OperationsPermissionSeeder::class);
        $this->seed(StaffRolePresetSeeder::class);
        $this->seed(DocumentTemplateSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_feature_is_disabled_by_default_and_blocks_client_pages(): void
    {
        $accountant = $this->staff('ar-disabled-accountant', 'accountant');
        $customer = $this->customer('ar-disabled-customer');

        $this->disableFeature();
        $this->assertFalse(app(CoreMarketCustomerReceivableService::class)->enabled());
        $this->actingAs($accountant)
            ->get(route('operations.customers.receivables.show', $customer))
            ->assertNotFound();
    }

    public function test_invoice_posting_is_idempotent_and_balance_is_debits_minus_credits(): void
    {
        $this->enableFeature();
        $manager = $this->staff('ar-manager', 'owner_general_manager');
        $customer = $this->customer('ar-customer');
        $order = $this->order($customer, 120);
        $service = app(CoreMarketCustomerReceivableService::class);

        $first = $service->createInvoiceEntryFromOrder($order, $manager);
        $second = $service->createInvoiceEntryFromOrder($order, $manager);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, CustomerLedgerEntry::query()->where('order_id', $order->id)->count());
        $this->assertSame(120.0, $service->customerBalance($customer));
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    public function test_customer_payment_allocates_safely_and_rejects_over_allocation(): void
    {
        $this->enableFeature();
        $accountant = $this->staff('ar-accountant', 'accountant');
        $customer = $this->customer('ar-payment-customer');
        $order = $this->order($customer, 100);
        $service = app(CoreMarketCustomerReceivableService::class);
        $invoice = $service->createInvoiceEntryFromOrder($order, $accountant);

        $payment = $service->recordCustomerPayment(
            $customer,
            40,
            'bank_transfer',
            $accountant,
            null,
            'ar-bank-payment-'.uniqid(),
            [['customer_ledger_entry_id' => $invoice->id, 'amount' => 40]]
        );

        $this->assertSame(40.0, (float) $payment->amount);
        $this->assertSame(60.0, $service->customerBalance($customer));
        $this->assertSame(40.0, $service->settledAmountForOrder($order));
        $this->assertSame(60.0, $service->outstandingAmountForOrder($order));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('outstanding amount');
        $service->recordCustomerPayment(
            $customer,
            70,
            'bank_transfer',
            $accountant,
            null,
            'ar-over-allocation-'.uniqid(),
            [['customer_ledger_entry_id' => $invoice->id, 'amount' => 70]]
        );
    }

    public function test_cash_payment_requires_open_shift_and_creates_one_cash_movement(): void
    {
        $this->enableFeature();
        $cashier = $this->staff('ar-cashier', 'cashier');
        $customer = $this->customer('ar-cash-customer');
        $service = app(CoreMarketCustomerReceivableService::class);

        try {
            $service->recordCustomerPayment($customer, 25, 'cash', $cashier, null, 'cash-without-shift');
            $this->fail('Cash payment without an open shift should fail.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('open cashbox shift', $exception->getMessage());
        }

        $cashbox = app(CashboxService::class)->createCashbox([
            'name' => 'AR Cashbox',
            'code' => 'AR-CASH-'.uniqid(),
            'currency' => 'USD',
            'status' => 'active',
        ]);
        $shift = app(CashboxService::class)->openShift($cashbox, $cashier, 0);
        $key = 'cash-customer-payment-'.uniqid();
        $first = $service->recordCustomerPayment($customer, 25, 'cash', $cashier, $shift, $key);
        $second = $service->recordCustomerPayment($customer, 25, 'cash', $cashier, $shift, $key);

        $this->assertSame($first->id, $second->id);
        $this->assertNotNull($first->cash_movement_id);
        $this->assertSame(1, CashMovement::query()
            ->where('reference_type', CustomerPayment::class)
            ->where('reference_id', $first->id)
            ->count());
    }

    public function test_statement_switches_to_ar_and_remains_customer_isolated(): void
    {
        $this->enableFeature();
        $manager = $this->staff('ar-statement-manager', 'owner_general_manager');
        $customer = $this->customer('ar-statement-customer');
        $other = $this->customer('ar-other-customer');
        $service = app(CoreMarketCustomerReceivableService::class);
        $service->createInvoiceEntryFromOrder($this->order($customer, 80, 'AR-CUSTOMER-ORDER'), $manager);
        $service->createInvoiceEntryFromOrder($this->order($other, 90, 'AR-OTHER-ORDER'), $manager);

        $statement = app(OperationsPdfService::class)->customerStatement($customer);

        $this->assertFalse($statement['isOperationalStatement']);
        $this->assertSame(['AR-CUSTOMER-ORDER'], $statement['rows']->pluck('reference')->all());
        $this->assertNotContains('AR-OTHER-ORDER', $statement['rows']->pluck('reference')->all());

        $this->disableFeature();
        $this->assertTrue(app(OperationsPdfService::class)->customerStatement($customer)['isOperationalStatement']);
    }

    public function test_roles_are_bounded_and_missing_shipping_address_is_null_safe(): void
    {
        $this->enableFeature();
        $accountant = $this->staff('ar-permission-accountant', 'accountant');
        $driver = $this->staff('ar-permission-driver', 'delivery_distribution');
        $cashier = $this->staff('ar-permission-cashier', 'cashier');
        $customer = $this->customer('ar-null-address-customer');
        $order = $this->order($customer, 45, shippingAddress: null);

        $this->assertTrue($accountant->can('customer_receivables.manage'));
        $this->assertFalse($driver->can('customer_receivables.view'));
        $this->actingAs($accountant)
            ->get(route('operations.customer-receivables.index'))
            ->assertOk();
        $this->actingAs($accountant)
            ->get(route('operations.customers.receivables.show', $customer))
            ->assertOk();
        $this->actingAs($driver)
            ->get(route('operations.customers.receivables.show', $customer))
            ->assertForbidden();
        $this->actingAs($cashier)
            ->get(route('operations.customers.receivables.show', $customer))
            ->assertForbidden();
        $this->actingAs($accountant)
            ->get(route('all_orders.show', encrypt($order->id)))
            ->assertOk()
            ->assertSee('Shipping address not provided');
    }

    private function enableFeature(): void
    {
        DB::table('business_settings')->updateOrInsert(
            ['type' => 'customer_accounts.enabled', 'lang' => null],
            ['value' => '1']
        );
        Cache::forget('business_settings');
    }

    private function disableFeature(): void
    {
        DB::table('business_settings')->updateOrInsert(
            ['type' => 'customer_accounts.enabled', 'lang' => null],
            ['value' => '0']
        );
        Cache::forget('business_settings');
    }

    private function order(User $customer, float $total, ?string $code = null, mixed $shippingAddress = 'default'): Order
    {
        $order = new Order();
        $order->forceFill([
            'user_id' => $customer->id,
            'shipping_address' => $shippingAddress === 'default'
                ? json_encode(['name' => $customer->name, 'city' => 'Beirut', 'address' => 'Customer Street'])
                : $shippingAddress,
            'shipping_type' => 'home_delivery',
            'order_from' => 'web',
            'delivery_status' => 'pending',
            'payment_type' => 'credit',
            'payment_status' => 'unpaid',
            'paid_amount' => 0,
            'grand_total' => $total,
            'code' => $code ?: 'AR-ORDER-'.uniqid(),
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
