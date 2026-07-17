<?php

namespace Tests\Feature;

use App\Models\CashMovement;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyPointMovement;
use App\Models\LoyaltyRule;
use App\Models\ProductStock;
use App\Models\User;
use App\Services\CashboxService;
use App\Services\CoreMarketFeatureAccessService;
use App\Services\WebPosService;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class WebPosCustomerLoyaltyServiceTest extends TestCase
{
    use DatabaseTransactions;

    private WebPosService $service;

    private CashboxService $cashboxes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('coremarket_testing', DB::getDatabaseName());
        $this->assertTrue(Schema::hasTable('loyalty_accounts'));
        $this->assertTrue(Schema::hasColumn('orders', 'pos_request_key'));

        $this->service = app(WebPosService::class);
        $this->cashboxes = app(CashboxService::class);
    }

    public function test_customer_search_returns_only_active_customers_without_creating_loyalty_accounts(): void
    {
        $customer = $this->customer(['name' => 'Alice Customer', 'phone' => '555001']);
        $this->customer(['name' => 'Alice Blocked', 'banned' => 1]);
        $this->user('staff', ['name' => 'Alice Staff']);

        $results = $this->service->searchCustomers('Alice');

        $this->assertCount(1, $results);
        $this->assertSame($customer->id, $results->first()['id']);
        $this->assertSame(0, LoyaltyAccount::query()->where('user_id', $customer->id)->count());
        $this->assertNull($results->first()['masked_email'] === $customer->email ? $results->first()['masked_email'] : null);
    }

    public function test_pos_customer_validation_rejects_banned_and_non_customer_users(): void
    {
        $banned = $this->customer(['banned' => 1]);

        foreach ([$banned->id, $this->user('staff')->id] as $customerId) {
            try {
                $this->service->validatePosCustomer($customerId);
                $this->fail('Expected the unavailable customer to be rejected.');
            } catch (DomainException $exception) {
                $this->assertSame('Selected POS customer is unavailable.', $exception->getMessage());
            }
        }
    }

    public function test_pos_checkout_supports_walk_in_and_selected_customer_sales(): void
    {
        $cashier = $this->user('staff');
        $this->openShift($cashier);
        $stock = $this->productStock($cashier, ['qty' => 5]);
        $customer = $this->customer();

        $walkIn = $this->sale($stock, $cashier, 'walk-in-' . uniqid());
        $selected = $this->sale($stock, $cashier, 'customer-' . uniqid(), $customer->id);

        $this->assertNull($walkIn->user_id);
        $this->assertSame($customer->id, $selected->user_id);
    }

    public function test_invalid_pos_customer_fails_before_creating_an_order(): void
    {
        $cashier = $this->user('staff');
        $this->openShift($cashier);
        $stock = $this->productStock($cashier);
        $ordersBefore = DB::table('orders')->count();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Selected POS customer is unavailable.');

        try {
            $this->sale($stock, $cashier, 'invalid-customer-' . uniqid(), $this->user('staff')->id);
        } finally {
            $this->assertSame($ordersBefore, DB::table('orders')->count());
        }
    }

    public function test_customer_pos_sale_earns_points_and_payloads_include_customer_and_loyalty(): void
    {
        $cashier = $this->user('staff');
        $this->openShift($cashier);
        $stock = $this->productStock($cashier, ['price' => 20]);
        $customer = $this->customer(['name' => 'Loyal POS Customer', 'phone' => '555009']);
        $this->rule(['earn_rate_amount' => 10, 'earn_rate_points' => 2, 'applies_to_order_from' => 'pos']);
        $walletsBefore = DB::table('wallets')->count();
        $journalsBefore = DB::table('journal_entries')->count();

        $order = $this->sale($stock, $cashier, 'loyal-customer-' . uniqid(), $customer->id, 30);
        $checkout = $this->service->checkoutSummaryPayload($order);
        $receipt = $this->service->receiptPayload($order);

        $this->assertDatabaseHas('loyalty_point_movements', [
            'reference_id' => $order->id,
            'movement_type' => 'earn',
            'direction' => 'in',
            'points' => 4,
        ]);
        $this->assertSame($customer->id, $checkout['customer']['id']);
        $this->assertSame('Loyal POS Customer', $receipt['customer']['name']);
        $this->assertSame(4, $receipt['loyalty']['points_earned']);
        $this->assertSame(0, $receipt['loyalty']['balance_before']);
        $this->assertSame(4, $receipt['loyalty']['balance_after']);
        $this->assertSame($walletsBefore, DB::table('wallets')->count());
        $this->assertSame($journalsBefore, DB::table('journal_entries')->count());
    }

    public function test_walk_in_sale_does_not_earn_and_duplicate_customer_request_is_idempotent(): void
    {
        $cashier = $this->user('staff');
        $this->openShift($cashier);
        $stock = $this->productStock($cashier, ['qty' => 5]);
        $customer = $this->customer();
        $otherCustomer = $this->customer();
        $this->rule(['applies_to_order_from' => 'pos']);
        $key = 'loyal-idempotent-' . uniqid();

        $walkIn = $this->sale($stock, $cashier, 'no-customer-' . uniqid());
        $first = $this->sale($stock, $cashier, $key, $customer->id);
        $second = $this->sale($stock, $cashier, $key, $customer->id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(0, LoyaltyPointMovement::query()->where('reference_id', $walkIn->id)->count());
        $this->assertSame(1, LoyaltyPointMovement::query()->where('reference_id', $first->id)->where('movement_type', 'earn')->count());
        $this->assertSame(1, CashMovement::query()->where('reference_id', $first->id)->where('movement_type', 'sale')->count());

        try {
            $this->sale($stock, $cashier, $key, $otherCustomer->id);
            $this->fail('Expected a customer mismatch for the idempotency key.');
        } catch (DomainException $exception) {
            $this->assertSame('POS request key is already associated with a different customer.', $exception->getMessage());
        }

        $this->assertSame($customer->id, $first->fresh()->user_id);
    }

    public function test_customer_pos_sale_skips_loyalty_when_feature_is_disabled(): void
    {
        $features = Mockery::mock(CoreMarketFeatureAccessService::class);
        $features->shouldReceive('enabled')->with('loyalty_points')->andReturnFalse();
        app()->instance(CoreMarketFeatureAccessService::class, $features);
        $this->service = app(WebPosService::class);

        $cashier = $this->user('staff');
        $this->openShift($cashier);
        $stock = $this->productStock($cashier);
        $customer = $this->customer();
        $this->rule(['applies_to_order_from' => 'pos']);

        $order = $this->sale($stock, $cashier, 'loyalty-disabled-' . uniqid(), $customer->id);

        $this->assertSame(0, LoyaltyPointMovement::query()->where('reference_id', $order->id)->count());
        $this->assertFalse($this->service->receiptPayload($order)['loyalty']['enabled']);
    }

    private function sale(ProductStock $stock, User $cashier, string $requestKey, ?int $customerId = null, float $paidAmount = 50)
    {
        return $this->service->createPosOrder([
            ['product_stock_id' => $stock->id, 'quantity' => 1],
        ], [
            'payment_type' => 'cash',
            'paid_amount' => $paidAmount,
            'customer_id' => $customerId,
        ], $cashier, $requestKey);
    }

    private function openShift(User $cashier): void
    {
        $cashbox = $this->cashboxes->createCashbox([
            'name' => 'Customer POS Cashbox',
            'code' => 'CUSTOMER-POS-' . uniqid(),
            'currency' => 'USD',
            'status' => 'active',
        ]);

        $this->cashboxes->openShift($cashbox, $cashier, 0);
    }

    private function productStock(User $owner, array $attributes = []): ProductStock
    {
        $now = now();
        $productId = DB::table('products')->insertGetId([
            'name' => $attributes['name'] ?? 'Customer POS Product ' . uniqid(),
            'user_id' => $owner->id,
            'category_id' => 1,
            'unit_price' => $attributes['price'] ?? 20,
            'purchase_price' => 10,
            'current_stock' => $attributes['qty'] ?? 5,
            'slug' => 'customer-pos-' . uniqid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $stockId = DB::table('product_stocks')->insertGetId([
            'product_id' => $productId,
            'variant' => '',
            'sku' => 'CUSTOMER-POS-' . uniqid(),
            'price' => $attributes['price'] ?? 20,
            'qty' => $attributes['qty'] ?? 5,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ProductStock::query()->with('product')->findOrFail($stockId);
    }

    private function customer(array $attributes = []): User
    {
        return $this->user('customer', $attributes);
    }

    private function user(string $type, array $attributes = []): User
    {
        $user = new User();
        $user->forceFill(array_merge([
            'name' => 'POS ' . ucfirst($type) . ' ' . uniqid(),
            'email' => uniqid('pos-' . $type) . '@example.test',
            'phone' => '555' . random_int(100000, 999999),
            'password' => bcrypt('Temporary123!'),
            'user_type' => $type,
            'banned' => 0,
            'email_verified_at' => now(),
        ], $attributes))->save();

        return $user;
    }

    private function rule(array $attributes = []): LoyaltyRule
    {
        return LoyaltyRule::query()->create(array_merge([
            'name' => 'POS loyalty rule ' . uniqid(),
            'is_active' => true,
            'earn_rate_amount' => 10,
            'earn_rate_points' => 1,
            'min_order_amount' => 0,
            'currency' => 'USD',
        ], $attributes));
    }
}
