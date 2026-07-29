<?php

namespace Tests\Feature;

use App\Models\CustomerAccountProfile;
use App\Models\CustomerLedgerEntry;
use App\Models\CustomerPayment;
use App\Models\Order;
use App\Models\ProductBranchPrice;
use App\Models\ProductStock;
use App\Models\StoreBranch;
use App\Models\User;
use App\Services\CashboxService;
use App\Services\CoreMarketCreditPaymentService;
use Database\Seeders\OperationsPermissionSeeder;
use Database\Seeders\StaffRolePresetSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CreditPaymentMethodTest extends TestCase
{
    use DatabaseTransactions;

    private StoreBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('coremarket_testing', DB::getDatabaseName());
        $this->assertTrue(Schema::hasTable('customer_account_profiles'));
        $this->assertTrue(Schema::hasTable('product_branch_prices'));
        $this->seed(OperationsPermissionSeeder::class);
        $this->seed(StaffRolePresetSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->branch = StoreBranch::query()->where('is_default', true)->firstOrFail();
        $this->setCoreFeatures();
    }

    protected function tearDown(): void
    {
        Cache::forget('business_settings');
        parent::tearDown();
    }

    public function test_feature_flags_are_off_by_default_and_hide_pos_account_payment(): void
    {
        $cashier = $this->staff('disabled-cashier', 'cashier');
        $this->setting('customer_accounts.pay_on_account_enabled', false);

        $this->actingAs($cashier)
            ->get(route('operations.pos'))
            ->assertOk()
            ->assertDontSee('id="pos-payment-account"', false);

        $this->assertFalse(app(CoreMarketCreditPaymentService::class)->posEnabled());
    }

    public function test_pos_account_payment_requires_customer_permission_and_active_credit_profile(): void
    {
        $cashier = $this->staff('policy-cashier', 'cashier');
        $withoutPermission = $this->staff('policy-no-permission', 'warehouse_keeper');
        $customer = $this->customer('policy-customer');
        $payments = app(CoreMarketCreditPaymentService::class);

        $this->assertSame('account_disabled', $payments->decision($customer, 20, 'pos')['reason']);
        $this->profile($customer, false, 100, 'active');
        $this->assertSame('credit_not_allowed', $payments->decision($customer, 20, 'pos')['reason']);

        foreach (['on_hold' => 'account_on_hold', 'blocked' => 'account_blocked'] as $status => $reason) {
            $this->profile($customer, true, 100, $status);
            $this->assertSame($reason, $payments->decision($customer, 20, 'pos')['reason']);
        }

        $this->profile($customer, true, 100, 'active');
        $this->assertSame('over_credit_limit', $payments->decision($customer, 100.01, 'pos')['reason']);
        $this->assertTrue($payments->decision($customer, 100, 'pos')['allowed']);
        $this->assertTrue($payments->canUsePos($cashier));
        $this->assertFalse($payments->canUsePos($withoutPermission));
    }

    public function test_overdue_customer_is_blocked_when_payment_terms_are_enforced(): void
    {
        $manager = $this->staff('overdue-manager', 'owner_general_manager');
        $customer = $this->customer('overdue-customer');
        $this->profile($customer, true, 500, 'active', 5);
        $oldOrder = $this->order($customer, 30, 'pay_on_account');
        $oldOrder->forceFill(['created_at' => now()->subDays(10), 'updated_at' => now()->subDays(10)])->save();
        app(\App\Services\CoreMarketCustomerReceivableService::class)
            ->createInvoiceEntryFromOrder($oldOrder->fresh('user'), $manager);

        $this->assertSame(
            'overdue_balance',
            app(CoreMarketCreditPaymentService::class)->decision($customer, 10, 'pos')['reason']
        );
    }

    public function test_pos_pay_on_account_uses_server_price_and_stock_then_posts_one_ar_invoice_without_cash(): void
    {
        $cashier = $this->staff('account-sale-cashier', 'cashier');
        $cashier->branches()->sync([$this->branch->id => ['is_primary' => true]]);
        $this->openShift($cashier);
        $customer = $this->customer('account-sale-customer');
        $this->profile($customer, true, 1000, 'active');
        $stock = $this->stock(10, 100);
        ProductBranchPrice::query()->create([
            'store_branch_id' => $this->branch->id,
            'product_id' => $stock->product_id,
            'product_stock_id' => $stock->id,
            'price' => 65,
            'is_active' => true,
        ]);
        $key = 'account-pos-'.uniqid();
        $payload = [
            'payment_type' => 'pay_on_account',
            'customer_id' => $customer->id,
            'pos_request_key' => $key,
            'items' => [[
                'product_id' => $stock->product_id,
                'product_stock_id' => $stock->id,
                'quantity' => 2,
            ]],
        ];

        $this->actingAs($cashier)->post(route('operations.pos.checkout'), $payload)->assertRedirect();
        $this->actingAs($cashier)->post(route('operations.pos.checkout'), $payload)->assertRedirect();

        $order = Order::query()->where('pos_request_key', $key)->firstOrFail();
        $this->assertSame('pay_on_account', $order->payment_type);
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame(0.0, (float) $order->paid_amount);
        $this->assertSame(130.0, (float) $order->orderDetails()->sole()->price);
        $this->assertSame(8.0, (float) $stock->fresh()->qty);
        $this->assertSame(1, CustomerLedgerEntry::query()->where('order_id', $order->id)->where('entry_type', 'invoice')->count());
        $this->assertDatabaseHas('customer_ledger_entries', [
            'order_id' => $order->id,
            'direction' => 'debit',
            'amount' => 130,
            'idempotency_key' => 'order:'.$order->id.':pay_on_account',
        ]);
        $this->assertSame(0, DB::table('cash_movements')->where('reference_type', Order::class)->where('reference_id', $order->id)->count());
        $this->assertSame(0, CustomerPayment::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(1, Order::query()->where('pos_request_key', $key)->count());
    }

    public function test_pos_server_rejects_walk_in_ineligible_and_unpermitted_account_sales(): void
    {
        $cashier = $this->staff('reject-cashier', 'cashier');
        $warehouse = $this->staff('reject-warehouse', 'warehouse_keeper');
        $this->openShift($cashier);
        $this->openShift($warehouse);
        $stock = $this->stock(5, 20);
        $base = [
            'payment_type' => 'pay_on_account',
            'items' => [['product_stock_id' => $stock->id, 'quantity' => 1]],
        ];

        $this->actingAs($cashier)->post(route('operations.pos.checkout'), $base + [
            'pos_request_key' => 'walk-in-'.uniqid(),
        ])->assertSessionHasErrors('pos');

        $customer = $this->customer('reject-customer');
        $this->actingAs($cashier)->post(route('operations.pos.checkout'), $base + [
            'customer_id' => $customer->id,
            'pos_request_key' => 'ineligible-'.uniqid(),
        ])->assertSessionHasErrors('pos');

        $this->profile($customer, true, 100, 'active');
        $this->actingAs($warehouse)->post(route('operations.pos.checkout'), $base + [
            'customer_id' => $customer->id,
            'pos_request_key' => 'unpermitted-'.uniqid(),
        ])->assertForbidden();
    }

    public function test_web_checkout_option_is_customer_only_and_uses_credit_decision(): void
    {
        $customer = $this->customer('web-credit-customer');
        $this->profile($customer, true, 500, 'active');

        $this->actingAs($customer);
        $eligible = view('frontend.partials.cart.payment_info', [
            'carts' => collect(),
            'total' => 50,
        ])->render();
        $this->assertStringContainsString('value="pay_on_account"', $eligible);
        $this->assertStringContainsString('Pay on Account', $eligible);

        $this->profile($customer, true, 500, 'blocked');
        $blocked = view('frontend.partials.cart.payment_info', [
            'carts' => collect(),
            'total' => 50,
        ])->render();
        $this->assertStringNotContainsString('value="pay_on_account"', $blocked);
        $this->assertStringContainsString('Pay on Account unavailable', $blocked);

        auth()->logout();
        $guest = view('frontend.partials.cart.payment_info', [
            'carts' => collect(),
            'total' => 50,
        ])->render();
        $this->assertStringNotContainsString('value="pay_on_account"', $guest);
    }

    public function test_web_account_post_is_unpaid_idempotent_and_has_no_cash_or_customer_payment(): void
    {
        $customer = $this->customer('web-post-customer');
        $this->profile($customer, true, 500, 'active', 30);
        $order = $this->order($customer, 75, 'pay_on_account');
        $payments = app(CoreMarketCreditPaymentService::class);

        $first = $payments->postOrder($order, $customer, 'web_checkout', $this->branch);
        $second = $payments->postOrder($order->fresh('user'), $customer, 'web_checkout', $this->branch);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('web_checkout', $first->metadata['source']);
        $this->assertSame('pay_on_account', $first->metadata['payment_method']);
        $this->assertSame($this->branch->id, $first->metadata['branch_id']);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $this->assertSame(0, DB::table('cash_movements')->where('reference_type', Order::class)->where('reference_id', $order->id)->count());
        $this->assertSame(0, CustomerPayment::query()->where('customer_id', $customer->id)->count());
    }

    private function setCoreFeatures(): void
    {
        foreach ([
            'customer_accounts.enabled' => true,
            'customer_accounts.credit_limits_enabled' => true,
            'customer_accounts.payment_terms_enabled' => true,
            'customer_accounts.pay_on_account_enabled' => true,
            'pos.pay_on_account_enabled' => true,
            'checkout.pay_on_account_enabled' => true,
            'pricing.branch_pricing_enabled' => true,
            'pricing.branch_pricing_priority' => 'branch_price_first',
        ] as $key => $value) {
            $this->setting($key, $value);
        }
    }

    private function setting(string $key, bool|string $value): void
    {
        DB::table('business_settings')->updateOrInsert(
            ['type' => $key, 'lang' => null],
            ['value' => is_bool($value) ? ($value ? '1' : '0') : $value]
        );
        Cache::forget('business_settings');
    }

    private function profile(User $customer, bool $allowed, ?float $limit, string $status, ?int $terms = 30): CustomerAccountProfile
    {
        return CustomerAccountProfile::query()->updateOrCreate(
            ['customer_id' => $customer->id],
            [
                'is_credit_allowed' => $allowed,
                'credit_limit' => $limit,
                'credit_limit_currency' => 'USD',
                'payment_terms_days' => $terms,
                'account_status' => $status,
            ]
        );
    }

    private function order(User $customer, float $total, string $method): Order
    {
        $order = new Order();
        $order->forceFill([
            'user_id' => $customer->id,
            'shipping_address' => json_encode(['name' => $customer->name]),
            'shipping_type' => 'home_delivery',
            'order_from' => 'web',
            'delivery_status' => 'pending',
            'payment_type' => $method,
            'payment_status' => 'unpaid',
            'paid_amount' => 0,
            'grand_total' => $total,
            'code' => 'ACCOUNT-'.uniqid(),
            'date' => time(),
            'viewed' => 0,
            'delivery_viewed' => 1,
            'commission_calculated' => 0,
            'notified' => 0,
        ])->save();

        return $order->fresh('user');
    }

    private function stock(float $quantity, float $price): ProductStock
    {
        $now = now();
        $owner = $this->customer('product-owner');
        $productId = DB::table('products')->insertGetId([
            'name' => 'Account Sale Product '.uniqid(),
            'user_id' => $owner->id,
            'category_id' => 1,
            'unit_price' => $price,
            'purchase_price' => 20,
            'current_stock' => $quantity,
            'slug' => 'account-product-'.uniqid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $stockId = DB::table('product_stocks')->insertGetId([
            'product_id' => $productId,
            'variant' => '',
            'sku' => 'ACCOUNT-SKU-'.uniqid(),
            'price' => $price,
            'qty' => $quantity,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ProductStock::query()->with('product')->findOrFail($stockId);
    }

    private function openShift(User $staff): void
    {
        $cashboxes = app(CashboxService::class);
        $cashbox = $cashboxes->createCashbox([
            'name' => 'Account Register '.uniqid(),
            'code' => 'ACCOUNT-'.uniqid(),
            'currency' => 'USD',
            'status' => 'active',
        ]);
        $cashboxes->openShift($cashbox, $staff, 0);
    }

    private function customer(string $prefix): User
    {
        $user = User::query()->create([
            'name' => ucwords(str_replace('-', ' ', $prefix)),
            'email' => $prefix.'-'.uniqid().'@example.test',
            'password' => bcrypt('Temporary123!'),
        ]);
        $user->forceFill(['user_type' => 'customer', 'banned' => 0])->save();

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
