<?php

namespace Tests\Feature;

use App\Models\LoyaltyAccount;
use App\Models\LoyaltyPointMovement;
use App\Models\LoyaltyRule;
use App\Models\Order;
use App\Models\ProductStock;
use App\Models\User;
use App\Services\CashboxService;
use App\Services\CoreMarketFeatureAccessService;
use App\Services\WebPosService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\InteractsWithCoreMarketTestSchema;
use Tests\TestCase;

class OperationsPosRedemptionApiTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithCoreMarketTestSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('coremarket_testing', DB::getDatabaseName());
        $this->assertTrue(Schema::hasColumn('orders', 'loyalty_points_redeemed'));
        config()->set('coremarket.runtime_snapshot.connection', 'mysql');
        $this->ensureBusinessSettingsTable();
        $this->ensurePermissionTables();
        $this->ensureLegacyUserColumns();
        $this->ensureAdminSupportTables();
        DB::table('business_settings')->where('type', 'coremarket_runtime_features')->delete();
        Cache::forget('business_settings');
        $this->setFeatures(true);
    }

    public function test_checkout_without_points_to_redeem_remains_a_normal_pos_sale(): void
    {
        $cashier = $this->cashier();
        $this->openShift($cashier);
        $stock = $this->stock($cashier);
        Sanctum::actingAs($cashier, ['operations:pos']);

        $response = $this->postJson(route('api.v2.operations.pos.checkout'), $this->checkoutPayload($stock, 'api-no-redeem-' . uniqid(), null, null, 20))
            ->assertCreated()
            ->assertJsonPath('data.loyalty', null);

        $order = Order::query()->findOrFail($response->json('data.order_id'));
        $this->assertSame(0, (int) $order->loyalty_points_redeemed);
        $this->assertSame(20.0, (float) $order->grand_total);
    }

    public function test_redemption_requires_customer_and_sufficient_balance_before_order_creation(): void
    {
        $cashier = $this->cashier(['pos.redeem_loyalty']);
        $this->openShift($cashier);
        $stock = $this->stock($cashier);
        $customer = $this->fundedCustomer(5);
        $this->rule();
        Sanctum::actingAs($cashier, ['operations:pos']);
        $ordersBefore = Order::query()->count();

        $this->postJson(route('api.v2.operations.pos.checkout'), $this->checkoutPayload($stock, 'api-redeem-no-customer-' . uniqid(), null, 10, 20))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Loyalty redemption requires a customer.');
        $this->assertSame($ordersBefore, Order::query()->count());

        $this->postJson(route('api.v2.operations.pos.checkout'), $this->checkoutPayload($stock, 'api-redeem-insufficient-' . uniqid(), $customer->id, 10, 20))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Insufficient loyalty points balance.');
        $this->assertSame($ordersBefore, Order::query()->count());
    }

    public function test_redemption_is_rejected_when_loyalty_feature_is_disabled(): void
    {
        $this->setFeatures(false);
        $cashier = $this->cashier(['pos.redeem_loyalty']);
        $this->openShift($cashier);
        $stock = $this->stock($cashier);
        $customer = $this->fundedCustomer(50);
        $this->rule();
        Sanctum::actingAs($cashier, ['operations:pos']);
        $ordersBefore = Order::query()->count();

        $this->postJson(route('api.v2.operations.pos.checkout'), $this->checkoutPayload($stock, 'api-redeem-disabled-' . uniqid(), $customer->id, 10, 20))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Loyalty redemption is disabled.');
        $this->assertSame($ordersBefore, Order::query()->count());
    }

    public function test_valid_redemption_returns_checkout_and_receipt_summaries_without_wallet_coupon_or_journal_side_effects(): void
    {
        $cashier = $this->cashier(['pos.receipts.view', 'pos.redeem_loyalty']);
        $this->openShift($cashier);
        $stock = $this->stock($cashier);
        $customer = $this->fundedCustomer(50);
        $this->rule(['redeem_points' => 10, 'redeem_value' => 2]);
        Sanctum::actingAs($cashier, ['operations:pos']);
        $walletsBefore = DB::table('wallets')->count();
        $userBalanceBefore = (float) $customer->balance;
        $journalsBefore = DB::table('journal_entries')->count();

        $response = $this->postJson(route('api.v2.operations.pos.checkout'), $this->checkoutPayload($stock, 'api-redeem-valid-' . uniqid(), $customer->id, 20, 17))
            ->assertCreated()
            ->assertJsonPath('data.customer.id', $customer->id)
            ->assertJsonPath('data.loyalty.points_redeemed', 20)
            ->assertJsonPath('data.loyalty.redemption_discount', 4)
            ->assertJsonPath('data.loyalty.points_earned', 1);

        $order = Order::query()->findOrFail($response->json('data.order_id'));
        $this->assertSame(20, (int) $order->loyalty_points_redeemed);
        $this->assertSame(4.0, (float) $order->loyalty_redemption_discount);
        $this->assertSame(16.0, (float) $order->grand_total);
        $this->assertSame(17.0, (float) $order->paid_amount);
        $this->assertSame(1.0, (float) $order->change_amount);
        $this->assertSame(0.0, (float) $order->coupon_discount);
        $this->assertEquals(20.0, $response->json('data.gross_total'));
        $this->assertEquals(16.0, $response->json('data.final_total'));

        $this->getJson(route('api.v2.operations.pos.receipt', $order))
            ->assertOk()
            ->assertJsonPath('data.loyalty.points_redeemed', 20)
            ->assertJsonPath('data.loyalty.redemption_discount', 4)
            ->assertJsonPath('data.customer.id', $customer->id)
            ->assertJsonPath('data.final_total', 16);

        $this->assertSame($walletsBefore, DB::table('wallets')->count());
        $this->assertSame($userBalanceBefore, (float) $customer->fresh()->balance);
        $this->assertSame($journalsBefore, DB::table('journal_entries')->count());
    }

    public function test_retry_with_same_redemption_is_idempotent_and_different_points_are_rejected_without_mutation(): void
    {
        $cashier = $this->cashier(['pos.redeem_loyalty']);
        $this->openShift($cashier);
        $stock = $this->stock($cashier, 5);
        $customer = $this->fundedCustomer(50);
        $this->rule();
        Sanctum::actingAs($cashier, ['operations:pos']);
        $key = 'api-redeem-idempotent-' . uniqid();
        $payload = $this->checkoutPayload($stock, $key, $customer->id, 10, 20);

        $first = $this->postJson(route('api.v2.operations.pos.checkout'), $payload)->assertCreated();
        $orderId = $first->json('data.order_id');
        $this->postJson(route('api.v2.operations.pos.checkout'), $payload)
            ->assertCreated()
            ->assertJsonPath('data.order_id', $orderId);

        $this->assertSame(1, LoyaltyPointMovement::query()->where('reference_id', $orderId)->where('movement_type', 'redeem')->count());
        $this->assertSame(1, LoyaltyPointMovement::query()->where('reference_id', $orderId)->where('movement_type', 'earn')->count());
        $this->assertSame(1, DB::table('inventory_movements')->where('order_id', $orderId)->where('movement_type', 'sale')->count());
        $this->assertSame(1, DB::table('cash_movements')->where('reference_id', $orderId)->where('movement_type', 'sale')->count());
        $this->assertSame(4, (int) $stock->fresh()->qty);

        $this->postJson(route('api.v2.operations.pos.checkout'), $this->checkoutPayload($stock, $key, $customer->id, 20, 20))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'POS request key is already associated with a different loyalty redemption.');
        $this->assertSame(10, (int) Order::query()->findOrFail($orderId)->loyalty_points_redeemed);
        $this->assertSame(1, LoyaltyPointMovement::query()->where('reference_id', $orderId)->where('movement_type', 'redeem')->count());
    }

    public function test_redemption_requires_the_dedicated_permission_but_normal_checkout_does_not(): void
    {
        $cashier = $this->cashier();
        $this->openShift($cashier);
        $stock = $this->stock($cashier, 5);
        $customer = $this->fundedCustomer(50);
        $this->rule();
        Sanctum::actingAs($cashier, ['operations:pos']);
        $ordersBefore = Order::query()->count();

        $this->postJson(route('api.v2.operations.pos.checkout'), $this->checkoutPayload(
            $stock,
            'api-redemption-forbidden-' . uniqid(),
            $customer->id,
            10,
            20
        ))->assertForbidden();
        $this->assertSame($ordersBefore, Order::query()->count());

        $normalKey = 'api-no-redemption-permission-' . uniqid();
        $this->postJson(route('api.v2.operations.pos.checkout'), $this->checkoutPayload(
            $stock,
            $normalKey,
            $customer->id,
            0,
            20
        ))->assertCreated();
        $this->assertDatabaseHas('orders', ['pos_request_key' => $normalKey]);
    }

    private function setFeatures(bool $loyalty): void
    {
        config()->set('coremarket.features.pos', true);
        config()->set('coremarket.features.cashbox_shifts', true);
        config()->set('coremarket.features.loyalty_points', $loyalty);
        app()->forgetInstance(CoreMarketFeatureAccessService::class);
        app()->forgetInstance(WebPosService::class);
    }

    private function cashier(array $extraPermissions = []): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::query()->firstOrCreate(['name' => 'POS Redemption API ' . uniqid(), 'guard_name' => 'web']);
        foreach (array_merge(['pos.sell'], $extraPermissions) as $permission) {
            $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }

        $cashier = $this->user('staff');
        $cashier->assignRole($role);

        return $cashier;
    }

    private function fundedCustomer(int $points): User
    {
        $customer = $this->user('customer');
        LoyaltyAccount::query()->create([
            'user_id' => $customer->id,
            'points_balance' => $points,
            'status' => 'active',
        ]);

        return $customer;
    }

    private function user(string $type): User
    {
        $user = new User();
        $user->forceFill([
            'name' => 'POS Redemption API ' . ucfirst($type) . ' ' . uniqid(),
            'email' => uniqid('pos-redemption-api-' . $type) . '@example.test',
            'phone' => '555' . random_int(100000, 999999),
            'password' => bcrypt('Temporary123!'),
            'user_type' => $type,
            'banned' => 0,
            'email_verified_at' => now(),
        ])->save();

        return $user;
    }

    private function openShift(User $cashier): void
    {
        $cashbox = app(CashboxService::class)->createCashbox([
            'name' => 'POS Redemption API Cashbox',
            'code' => 'POS-REDEMPTION-API-' . uniqid(),
            'currency' => 'USD',
            'status' => 'active',
        ]);
        app(CashboxService::class)->openShift($cashbox, $cashier, 0);
    }

    private function stock(User $owner, int $quantity = 5): ProductStock
    {
        $now = now();
        $productId = DB::table('products')->insertGetId([
            'name' => 'POS Redemption API Product ' . uniqid(),
            'user_id' => $owner->id,
            'category_id' => 1,
            'unit_price' => 20,
            'purchase_price' => 10,
            'current_stock' => $quantity,
            'slug' => 'pos-redemption-api-' . uniqid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $stockId = DB::table('product_stocks')->insertGetId([
            'product_id' => $productId,
            'variant' => '',
            'sku' => 'POS-REDEMPTION-API-' . uniqid(),
            'price' => 20,
            'qty' => $quantity,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ProductStock::query()->findOrFail($stockId);
    }

    private function rule(array $attributes = []): LoyaltyRule
    {
        return LoyaltyRule::query()->create(array_merge([
            'name' => 'POS Redemption API Rule ' . uniqid(),
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

    private function checkoutPayload(ProductStock $stock, string $key, ?int $customerId, ?int $pointsToRedeem, float $paidAmount): array
    {
        $payload = [
            'items' => [[
                'product_id' => $stock->product_id,
                'product_stock_id' => $stock->id,
                'quantity' => 1,
            ]],
            'customer_id' => $customerId,
            'paid_amount' => $paidAmount,
            'pos_request_key' => $key,
        ];
        if ($pointsToRedeem !== null) {
            $payload['points_to_redeem'] = $pointsToRedeem;
        }

        return $payload;
    }
}
