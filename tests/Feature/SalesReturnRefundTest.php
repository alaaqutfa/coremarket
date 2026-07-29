<?php

namespace Tests\Feature;

use App\Models\CashMovement;
use App\Models\CustomerLedgerEntry;
use App\Models\CustomerPayment;
use App\Models\Order;
use App\Models\SalesReturn;
use App\Models\SalesReturnRefund;
use App\Models\User;
use App\Services\CashboxService;
use App\Services\CoreMarketCustomerReceivableService;
use App\Services\CoreMarketSalesReturnRefundService;
use App\Services\OperationsPdfService;
use App\Services\SalesReturnService;
use Database\Seeders\OperationsPermissionSeeder;
use Database\Seeders\StaffRolePresetSeeder;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SalesReturnRefundTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('coremarket_testing', DB::getDatabaseName());
        $this->assertTrue(Schema::hasTable('sales_return_refunds'));
        $this->seed(OperationsPermissionSeeder::class);
        $this->seed(StaffRolePresetSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->setting('customer_accounts.enabled', true);
        config()->set('coremarket.features.returns_management', true);
    }

    protected function tearDown(): void
    {
        Cache::forget('business_settings');
        parent::tearDown();
    }

    public function test_completed_return_creates_partial_account_credit_once_and_reduces_balance(): void
    {
        [$return, $customer, $actor] = $this->completedReturn('pay_on_account');
        CustomerLedgerEntry::query()->create([
            'customer_id' => $customer->id,
            'order_id' => $return->order_id,
            'entry_type' => 'invoice',
            'direction' => 'debit',
            'amount' => 100,
            'currency' => 'USD',
            'occurred_at' => now()->subDay(),
            'description' => 'Test invoice',
            'idempotency_key' => 'refund-test-invoice-'.$return->id,
            'created_by' => $actor->id,
        ]);
        $service = app(CoreMarketSalesReturnRefundService::class);
        $key = 'account-credit-'.$return->id;

        $first = $service->creditCustomerAccount($return, 30, $actor, $key);
        $second = $service->creditCustomerAccount($return, 30, $actor, $key);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('customer_account_credit', $first->refund_method);
        $this->assertNotNull($first->customer_ledger_entry_id);
        $this->assertSame(1, SalesReturnRefund::query()->where('idempotency_key', $key)->count());
        $this->assertDatabaseHas('customer_ledger_entries', [
            'id' => $first->customer_ledger_entry_id,
            'sales_return_id' => $return->id,
            'entry_type' => 'credit_note',
            'direction' => 'credit',
            'amount' => 30,
        ]);
        $this->assertSame(70.0, app(CoreMarketCustomerReceivableService::class)->customerBalance($customer));
        $this->assertSame(0, CustomerPayment::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(0, CashMovement::query()->where('reference_type', SalesReturnRefund::class)->count());
        $this->assertSame(30.0, $service->refundedAmount($return));
        $this->assertSame(30.0, $service->remainingRefundableAmount($return));

        $statement = app(OperationsPdfService::class)->customerStatement($customer);
        $this->assertFalse($statement['isOperationalStatement']);
        $this->assertContains('credit_note', $statement['rows']->pluck('entry_type')->all());
    }

    public function test_draft_return_and_over_refund_are_rejected(): void
    {
        [$return, , $actor] = $this->returnFixture('cash', false);
        $service = app(CoreMarketSalesReturnRefundService::class);

        try {
            $service->creditCustomerAccount($return, 10, $actor, 'draft-credit-'.$return->id);
            $this->fail('Draft return should not be credited.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('completed', $exception->getMessage());
        }

        $return = app(SalesReturnService::class)->complete($return, $actor->id);
        $service->creditCustomerAccount($return, 25, $actor, 'partial-credit-'.$return->id);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('remaining refundable');
        $service->creditCustomerAccount($return, 36, $actor, 'over-credit-'.$return->id);
    }

    public function test_cash_refund_creates_one_cash_out_and_no_ar_payment(): void
    {
        [$return, $customer, $cashier] = $this->completedReturn('cash', 'cashier');
        $cashboxes = app(CashboxService::class);
        $cashbox = $cashboxes->createCashbox([
            'name' => 'Returns Cashbox',
            'code' => 'RETURN-CASH-'.uniqid(),
            'currency' => 'USD',
            'status' => 'active',
        ]);
        $shift = $cashboxes->openShift($cashbox, $cashier, 100);
        $service = app(CoreMarketSalesReturnRefundService::class);
        $key = 'cash-refund-'.$return->id;

        $first = $service->refundToCash($return, 20, $cashier, $shift, $key);
        $second = $service->refundToCash($return, 20, $cashier, $shift, $key);

        $this->assertSame($first->id, $second->id);
        $this->assertNotNull($first->cash_movement_id);
        $this->assertDatabaseHas('cash_movements', [
            'id' => $first->cash_movement_id,
            'movement_type' => 'sales_return_refund',
            'direction' => 'out',
            'amount' => 20,
            'reference_type' => SalesReturnRefund::class,
            'reference_id' => $first->id,
        ]);
        $this->assertSame(80.0, (float) $shift->fresh()->expected_cash);
        $this->assertSame(0, CustomerPayment::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(0, CustomerLedgerEntry::query()->where('sales_return_id', $return->id)->count());
        $this->assertSame('paid', $return->order->fresh()->payment_status);
    }

    public function test_cash_refund_requires_open_shift_and_cashier_own_shift(): void
    {
        [$return, , $cashier] = $this->completedReturn('cash', 'cashier');
        $other = $this->staff('other-cashier', 'cashier');
        $cashboxes = app(CashboxService::class);
        $cashbox = $cashboxes->createCashbox([
            'name' => 'Other Cashbox',
            'code' => 'OTHER-CASH-'.uniqid(),
            'currency' => 'USD',
            'status' => 'active',
        ]);
        $shift = $cashboxes->openShift($cashbox, $other, 100);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('their own open shift');
        app(CoreMarketSalesReturnRefundService::class)
            ->refundToCash($return, 10, $cashier, $shift, 'wrong-shift-'.$return->id);
    }

    public function test_refund_permissions_and_sensitive_return_values_are_bounded(): void
    {
        [$return] = $this->completedReturn('cash');
        $cashier = $this->staff('ui-cashier', 'cashier');
        $accountant = $this->staff('ui-accountant', 'accountant');
        $driver = $this->staff('ui-driver', 'delivery_distribution');

        $this->assertTrue($cashier->can('sales_returns.refunds.cash'));
        $this->assertFalse($cashier->can('sales_returns.refunds.credit_account'));
        $this->assertTrue($accountant->can('sales_returns.refunds.credit_account'));
        $this->assertFalse($driver->can('sales_returns.refunds.view'));
        try {
            app(CoreMarketSalesReturnRefundService::class)
                ->creditCustomerAccount($return, 5, $driver, 'driver-credit-'.$return->id);
            $this->fail('Delivery staff should not post customer account credit.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('not authorized', $exception->getMessage());
        }

        $this->actingAs($cashier)
            ->get(route('operations.sales-returns.show', $return))
            ->assertOk()
            ->assertSee('Refund Cash')
            ->assertDontSee('Credit Customer Account')
            ->assertDontSee('Cost Price')
            ->assertDontSee('Profit Reversal');
        $this->actingAs($accountant)
            ->get(route('operations.sales-returns.show', $return))
            ->assertOk()
            ->assertSee('Credit Customer Account');
        $this->actingAs($driver)
            ->get(route('operations.sales-returns.show', $return))
            ->assertForbidden();
    }

    public function test_existing_sales_return_completion_still_restores_stock_once(): void
    {
        [$return, , $actor, $stockId] = $this->returnFixture('cash', false);
        $before = (float) DB::table('product_stocks')->where('id', $stockId)->value('qty');

        app(SalesReturnService::class)->complete($return, $actor->id);
        app(SalesReturnService::class)->complete($return->fresh(), $actor->id);

        $this->assertSame($before + 2, (float) DB::table('product_stocks')->where('id', $stockId)->value('qty'));
        $this->assertSame(1, DB::table('inventory_movements')->where('movement_type', 'sale_reversal')->count());
    }

    private function completedReturn(string $paymentType, string $actorRole = 'owner_general_manager'): array
    {
        [$return, $customer, $actor, $stockId] = $this->returnFixture($paymentType, false, $actorRole);

        return [
            app(SalesReturnService::class)->complete($return, $actor->id),
            $customer,
            $actor,
            $stockId,
        ];
    }

    private function returnFixture(
        string $paymentType,
        bool $complete = false,
        string $actorRole = 'owner_general_manager'
    ): array {
        $customer = $this->customer('return-customer');
        $actor = $this->staff('return-actor', $actorRole);
        $now = now();
        $productId = DB::table('products')->insertGetId([
            'name' => 'Refund Product',
            'user_id' => 1,
            'category_id' => 1,
            'unit_price' => 30,
            'purchase_price' => 12,
            'current_stock' => 5,
            'slug' => 'refund-product-'.uniqid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $stockId = DB::table('product_stocks')->insertGetId([
            'product_id' => $productId,
            'variant' => '',
            'sku' => 'REFUND-'.uniqid(),
            'price' => 30,
            'qty' => 5,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $order = new Order();
        $order->forceFill([
            'user_id' => $customer->id,
            'shipping_type' => 'home_delivery',
            'order_from' => 'web',
            'delivery_status' => 'delivered',
            'payment_type' => $paymentType,
            'payment_status' => $paymentType === 'pay_on_account' ? 'unpaid' : 'paid',
            'paid_amount' => $paymentType === 'pay_on_account' ? 0 : 60,
            'grand_total' => 60,
            'code' => 'REFUND-ORDER-'.uniqid(),
            'date' => time(),
            'viewed' => 0,
            'delivery_viewed' => 1,
            'commission_calculated' => 0,
            'notified' => 0,
        ])->save();
        $detailId = DB::table('order_details')->insertGetId([
            'order_id' => $order->id,
            'product_id' => $productId,
            'variation' => '',
            'price' => 60,
            'tax' => 0,
            'shipping_cost' => 0,
            'quantity' => 2,
            'cost_price' => 12,
            'cost_source' => 'product_purchase_price',
            'total_cost' => 24,
            'profit_amount' => 36,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $return = app(SalesReturnService::class)->create(
            $order,
            [['order_detail_id' => $detailId, 'quantity' => 2]],
            ['reason' => 'Customer return'],
            $actor->id
        );

        return [
            $complete ? app(SalesReturnService::class)->complete($return, $actor->id) : $return,
            $customer,
            $actor,
            $stockId,
        ];
    }

    private function setting(string $type, bool $enabled): void
    {
        DB::table('business_settings')->updateOrInsert(
            ['type' => $type, 'lang' => null],
            ['value' => $enabled ? '1' : '0']
        );
        Cache::forget('business_settings');
    }

    private function customer(string $prefix): User
    {
        $user = User::query()->create([
            'name' => 'Refund Customer',
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
