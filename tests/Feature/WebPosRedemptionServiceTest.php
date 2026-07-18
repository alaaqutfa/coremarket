<?php

namespace Tests\Feature;

use App\Models\CashMovement;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyPointMovement;
use App\Models\LoyaltyRule;
use App\Models\Order;
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

class WebPosRedemptionServiceTest extends TestCase
{
    use DatabaseTransactions;

    private WebPosService $service;

    private CashboxService $cashboxes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('coremarket_testing', DB::getDatabaseName());
        $this->assertTrue(Schema::hasColumn('orders', 'loyalty_points_redeemed'));
        app()->forgetInstance(CoreMarketFeatureAccessService::class);
        $this->service = app(WebPosService::class);
        $this->cashboxes = app(CashboxService::class);
    }

    public function test_walk_in_checkout_without_redemption_still_works(): void
    {
        $cashier = $this->user('staff');
        $this->openShift($cashier);
        $stock = $this->stock($cashier);

        $order = $this->sale($stock, $cashier, 'walk-in-' . uniqid(), null, 0, 20);

        $this->assertNull($order->user_id);
        $this->assertSame(0, (int) $order->loyalty_points_redeemed);
        $this->assertSame(0.0, (float) $order->loyalty_redemption_discount);
    }

    public function test_redemption_requires_customer_and_enabled_loyalty_before_creating_order(): void
    {
        $cashier = $this->user('staff');
        $this->openShift($cashier);
        $stock = $this->stock($cashier);
        $orders = DB::table('orders')->count();

        $this->expectDomainException(fn () => $this->sale($stock, $cashier, 'no-customer-' . uniqid(), null, 10, 20), 'Loyalty redemption requires a customer.');
        $this->assertSame($orders, DB::table('orders')->count());

        $features = Mockery::mock(CoreMarketFeatureAccessService::class);
        $features->shouldReceive('enabled')->with('loyalty_points')->andReturnFalse();
        app()->instance(CoreMarketFeatureAccessService::class, $features);
        $this->service = app(WebPosService::class);
        $customer = $this->fundedCustomer(20);

        $this->expectDomainException(fn () => $this->sale($stock, $cashier, 'disabled-' . uniqid(), $customer->id, 10, 20), 'Loyalty redemption is disabled.');
        $this->assertSame($orders, DB::table('orders')->count());
    }

    public function test_insufficient_redemption_balance_is_rejected_before_creating_order(): void
    {
        $cashier = $this->user('staff');
        $this->openShift($cashier);
        $stock = $this->stock($cashier);
        $customer = $this->fundedCustomer(5);
        $this->rule();
        $orders = DB::table('orders')->count();

        $this->expectDomainException(fn () => $this->sale($stock, $cashier, 'insufficient-' . uniqid(), $customer->id, 10, 20), 'Insufficient loyalty points balance.');
        $this->assertSame($orders, DB::table('orders')->count());
    }

    public function test_redemption_uses_final_total_for_cash_change_earn_and_payloads(): void
    {
        $cashier = $this->user('staff');
        $shift = $this->openShift($cashier);
        $stock = $this->stock($cashier, ['price' => 20]);
        $customer = $this->fundedCustomer(50);
        $this->rule(['redeem_points' => 10, 'redeem_value' => 2, 'earn_rate_amount' => 10, 'earn_rate_points' => 1]);
        $wallets = DB::table('wallets')->count();
        $journals = DB::table('journal_entries')->count();

        $order = $this->sale($stock, $cashier, 'redeem-' . uniqid(), $customer->id, 20, 17);
        $checkout = $this->service->checkoutSummaryPayload($order);
        $receipt = $this->service->receiptPayload($order);

        $this->assertSame(20, (int) $order->loyalty_points_redeemed);
        $this->assertSame(4.0, (float) $order->loyalty_redemption_discount);
        $this->assertSame(16.0, (float) $order->grand_total);
        $this->assertSame(1.0, (float) $order->change_amount);
        $this->assertSame(0.0, (float) $order->coupon_discount);
        $this->assertDatabaseHas('cash_movements', ['cashier_shift_id' => $shift->id, 'reference_id' => $order->id, 'movement_type' => 'sale', 'amount' => '16.000000']);
        $this->assertDatabaseHas('loyalty_point_movements', ['reference_id' => $order->id, 'movement_type' => 'redeem', 'direction' => 'out', 'points' => 20]);
        $this->assertDatabaseHas('loyalty_point_movements', ['reference_id' => $order->id, 'movement_type' => 'earn', 'direction' => 'in', 'points' => 1]);
        $this->assertSame(20, $checkout['loyalty']['points_redeemed']);
        $this->assertSame(4.0, $receipt['loyalty']['redemption_discount']);
        $this->assertSame(1, $receipt['loyalty']['points_earned']);
        $this->assertSame($wallets, DB::table('wallets')->count());
        $this->assertSame($journals, DB::table('journal_entries')->count());
    }

    public function test_same_request_key_is_idempotent_and_different_redemption_is_rejected(): void
    {
        $cashier = $this->user('staff');
        $this->openShift($cashier);
        $stock = $this->stock($cashier, ['qty' => 5]);
        $customer = $this->fundedCustomer(50);
        $this->rule();
        $key = 'redeem-idempotent-' . uniqid();

        $first = $this->sale($stock, $cashier, $key, $customer->id, 10, 20);
        $second = $this->sale($stock, $cashier, $key, $customer->id, 10, 20);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, LoyaltyPointMovement::query()->where('reference_id', $first->id)->where('movement_type', 'redeem')->count());
        $this->assertSame(1, CashMovement::query()->where('reference_id', $first->id)->where('movement_type', 'sale')->count());
        $this->expectDomainException(fn () => $this->sale($stock, $cashier, $key, $customer->id, 20, 20), 'POS request key is already associated with a different loyalty redemption.');
        $this->assertSame(10, (int) $first->fresh()->loyalty_points_redeemed);
    }

    private function sale(ProductStock $stock, User $cashier, string $key, ?int $customerId, int $points, float $paidAmount): Order
    {
        return $this->service->createPosOrder([
            ['product_stock_id' => $stock->id, 'quantity' => 1],
        ], [
            'payment_type' => 'cash',
            'paid_amount' => $paidAmount,
            'customer_id' => $customerId,
            'points_to_redeem' => $points,
        ], $cashier, $key);
    }

    private function fundedCustomer(int $points): User
    {
        $customer = $this->user('customer');
        $account = LoyaltyAccount::query()->create(['user_id' => $customer->id, 'points_balance' => $points, 'status' => 'active']);

        return $customer;
    }

    private function rule(array $attributes = []): LoyaltyRule
    {
        return LoyaltyRule::query()->create(array_merge([
            'name' => 'POS redemption rule ' . uniqid(),
            'is_active' => true,
            'earn_rate_amount' => 10,
            'earn_rate_points' => 1,
            'min_order_amount' => 0,
            'redeem_points' => 10,
            'redeem_value' => 1,
            'min_redeem_points' => 0,
            'allow_pos_redeem' => true,
            'allow_storefront_redeem' => false,
            'currency' => 'USD',
        ], $attributes));
    }

    private function openShift(User $cashier)
    {
        $cashbox = $this->cashboxes->createCashbox([
            'name' => 'POS Redemption Cashbox',
            'code' => 'POS-REDEEM-' . uniqid(),
            'currency' => 'USD',
            'status' => 'active',
        ]);

        return $this->cashboxes->openShift($cashbox, $cashier, 0);
    }

    private function stock(User $owner, array $attributes = []): ProductStock
    {
        $now = now();
        $productId = DB::table('products')->insertGetId([
            'name' => 'POS Redemption Product ' . uniqid(),
            'user_id' => $owner->id,
            'category_id' => 1,
            'unit_price' => $attributes['price'] ?? 20,
            'purchase_price' => 10,
            'current_stock' => $attributes['qty'] ?? 5,
            'slug' => 'pos-redemption-' . uniqid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $stockId = DB::table('product_stocks')->insertGetId([
            'product_id' => $productId,
            'variant' => '',
            'sku' => 'POS-REDEEM-' . uniqid(),
            'price' => $attributes['price'] ?? 20,
            'qty' => $attributes['qty'] ?? 5,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ProductStock::query()->with('product')->findOrFail($stockId);
    }

    private function user(string $type): User
    {
        $user = new User();
        $user->forceFill([
            'name' => 'POS Redemption ' . ucfirst($type),
            'email' => uniqid('pos-redemption-' . $type) . '@example.test',
            'password' => bcrypt('Temporary123!'),
            'user_type' => $type,
            'banned' => 0,
            'email_verified_at' => now(),
        ])->save();

        return $user;
    }

    private function expectDomainException(callable $callback, string $message): void
    {
        try {
            $callback();
            $this->fail('Expected DomainException was not thrown.');
        } catch (DomainException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }
}
