<?php

namespace Tests\Feature;

use App\Models\LoyaltyAccount;
use App\Models\LoyaltyPointMovement;
use App\Models\LoyaltyRule;
use App\Models\ProductStock;
use App\Models\User;
use App\Services\CashboxService;
use App\Services\CoreMarketFeatureAccessService;
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

class OperationsPosCustomerLoyaltyApiTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithCoreMarketTestSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('coremarket_testing', DB::getDatabaseName());
        $this->assertTrue(Schema::hasTable('loyalty_accounts'));
        config()->set('coremarket.runtime_snapshot.connection', 'mysql');
        $this->ensureBusinessSettingsTable();
        $this->ensurePermissionTables();
        $this->ensureLegacyUserColumns();
        $this->ensureAdminSupportTables();
        $this->clearRuntimeFeatures();
        $this->setFeatures(true, true, true);
    }

    public function test_customer_search_requires_authentication_features_and_pos_view_permission(): void
    {
        $url = route('api.v2.operations.pos.customers.search', ['q' => 'Al']);
        $this->getJson($url)->assertUnauthorized();

        $user = $this->staff([]);
        Sanctum::actingAs($user, ['operations:pos']);
        $this->setFeatures(false, true, true);
        $this->getJson($url)->assertNotFound();

        $this->setFeatures(true, false, true);
        $this->getJson($url)->assertNotFound();

        $this->setFeatures(true, true, true);
        $this->getJson($url)->assertForbidden();
    }

    public function test_customer_search_returns_only_active_customers_without_creating_loyalty_accounts(): void
    {
        $cashier = $this->staff(['pos.view']);
        $customer = $this->customer(['name' => 'Alice API Customer']);
        $this->customer(['name' => 'Alice Blocked', 'banned' => 1]);
        $this->staff([], ['name' => 'Alice Staff']);
        Sanctum::actingAs($cashier, ['operations:pos']);

        $this->getJson(route('api.v2.operations.pos.customers.search', ['q' => 'Alice']))
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $customer->id)
            ->assertJsonPath('data.items.0.loyalty.enabled', true);

        $this->assertSame(0, LoyaltyAccount::query()->where('user_id', $customer->id)->count());
    }

    public function test_customer_search_keeps_selection_available_when_loyalty_is_disabled(): void
    {
        $cashier = $this->staff(['pos.view']);
        $customer = $this->customer(['name' => 'No Loyalty Customer']);
        $this->setFeatures(true, true, false);
        Sanctum::actingAs($cashier, ['operations:pos']);

        $this->getJson(route('api.v2.operations.pos.customers.search', ['q' => 'Loyalty']))
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $customer->id)
            ->assertJsonPath('data.items.0.loyalty', null);
    }

    public function test_checkout_persists_customer_awards_loyalty_and_is_idempotent(): void
    {
        $cashier = $this->staff(['pos.sell']);
        $this->openShift($cashier);
        $stock = $this->stock($cashier, ['qty' => 5, 'price' => 20]);
        $customer = $this->customer();
        $otherCustomer = $this->customer();
        $this->rule(['applies_to_order_from' => 'pos', 'earn_rate_amount' => 10, 'earn_rate_points' => 2]);
        Sanctum::actingAs($cashier, ['operations:pos']);
        $walletsBefore = DB::table('wallets')->count();
        $journalsBefore = DB::table('journal_entries')->count();
        $key = 'api-customer-' . uniqid();
        $payload = $this->checkoutPayload($stock, $key, $customer->id, 30);

        $response = $this->postJson(route('api.v2.operations.pos.checkout'), $payload)
            ->assertCreated()
            ->assertJsonPath('data.customer.id', $customer->id)
            ->assertJsonPath('data.loyalty.points_earned', 4);
        $orderId = $response->json('data.order_id');

        $this->postJson(route('api.v2.operations.pos.checkout'), $payload)
            ->assertCreated()
            ->assertJsonPath('data.order_id', $orderId);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'user_id' => $customer->id]);
        $this->assertSame(1, LoyaltyPointMovement::query()->where('reference_id', $orderId)->where('movement_type', 'earn')->count());
        $this->assertSame(4, (int) $stock->fresh()->qty);
        $this->assertSame($walletsBefore, DB::table('wallets')->count());
        $this->assertSame($journalsBefore, DB::table('journal_entries')->count());

        $this->postJson(route('api.v2.operations.pos.checkout'), $this->checkoutPayload($stock, $key, $otherCustomer->id, 30))
            ->assertUnprocessable();
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'user_id' => $customer->id]);
    }

    public function test_walk_in_and_invalid_customer_checkout_behave_safely(): void
    {
        $cashier = $this->staff(['pos.sell']);
        $this->openShift($cashier);
        $stock = $this->stock($cashier, ['qty' => 3]);
        Sanctum::actingAs($cashier, ['operations:pos']);

        $walkIn = $this->postJson(route('api.v2.operations.pos.checkout'), $this->checkoutPayload($stock, 'walk-in-' . uniqid(), null, 20))
            ->assertCreated()
            ->assertJsonPath('data.customer', null)
            ->assertJsonPath('data.loyalty', null);
        $ordersBefore = DB::table('orders')->count();

        $this->postJson(route('api.v2.operations.pos.checkout'), $this->checkoutPayload($stock, 'invalid-' . uniqid(), $this->staff([])->id, 20))
            ->assertConflict();
        $this->assertSame($ordersBefore, DB::table('orders')->count());
        $this->assertNotNull($walkIn->json('data.order_id'));
    }

    public function test_receipt_includes_customer_and_hides_loyalty_when_disabled(): void
    {
        $cashier = $this->staff(['pos.sell', 'pos.receipts.view']);
        $this->openShift($cashier);
        $stock = $this->stock($cashier);
        $customer = $this->customer(['name' => 'Receipt API Customer']);
        $this->rule(['applies_to_order_from' => 'pos']);
        Sanctum::actingAs($cashier, ['operations:pos']);

        $orderId = $this->postJson(route('api.v2.operations.pos.checkout'), $this->checkoutPayload($stock, 'receipt-customer-' . uniqid(), $customer->id, 20))
            ->assertCreated()
            ->json('data.order_id');

        $this->getJson(route('api.v2.operations.pos.receipt', $orderId))
            ->assertOk()
            ->assertJsonPath('data.customer.id', $customer->id)
            ->assertJsonPath('data.loyalty.enabled', true);

        $this->setFeatures(true, true, false);
        $this->getJson(route('api.v2.operations.pos.receipt', $orderId))
            ->assertOk()
            ->assertJsonPath('data.customer.id', $customer->id)
            ->assertJsonPath('data.loyalty.enabled', false);
    }

    private function setFeatures(bool $pos, bool $cashbox, bool $loyalty): void
    {
        config()->set('coremarket.features.pos', $pos);
        config()->set('coremarket.features.cashbox_shifts', $cashbox);
        config()->set('coremarket.features.loyalty_points', $loyalty);
        app()->forgetInstance(CoreMarketFeatureAccessService::class);
    }

    private function clearRuntimeFeatures(): void
    {
        DB::table('business_settings')->where('type', 'coremarket_runtime_features')->delete();
        Cache::forget('business_settings');
        app()->forgetInstance(CoreMarketFeatureAccessService::class);
    }

    private function staff(array $permissions, array $attributes = []): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::query()->firstOrCreate(['name' => 'POS Customer API ' . uniqid(), 'guard_name' => 'web']);
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }

        $user = new User();
        $user->forceFill(array_merge([
            'name' => 'POS Customer API Staff',
            'email' => uniqid('pos-customer-api') . '@example.test',
            'phone' => '555' . random_int(100000, 999999),
            'password' => bcrypt('Temporary123!'),
            'user_type' => 'staff',
            'banned' => 0,
            'email_verified_at' => now(),
        ], $attributes))->save();
        $user->assignRole($role);

        return $user;
    }

    private function customer(array $attributes = []): User
    {
        $user = new User();
        $user->forceFill(array_merge([
            'name' => 'POS API Customer ' . uniqid(),
            'email' => uniqid('pos-api-customer') . '@example.test',
            'phone' => '555' . random_int(100000, 999999),
            'password' => bcrypt('Temporary123!'),
            'user_type' => 'customer',
            'banned' => 0,
            'email_verified_at' => now(),
        ], $attributes))->save();

        return $user;
    }

    private function openShift(User $cashier): void
    {
        $cashbox = app(CashboxService::class)->createCashbox([
            'name' => 'POS Customer API Cashbox',
            'code' => 'POS-CUSTOMER-API-' . uniqid(),
            'currency' => 'USD',
            'status' => 'active',
        ]);

        app(CashboxService::class)->openShift($cashbox, $cashier, 0);
    }

    private function stock(User $owner, array $attributes = []): ProductStock
    {
        $now = now();
        $productId = DB::table('products')->insertGetId([
            'name' => 'POS Customer API Product ' . uniqid(),
            'user_id' => $owner->id,
            'category_id' => 1,
            'unit_price' => $attributes['price'] ?? 20,
            'purchase_price' => 10,
            'current_stock' => $attributes['qty'] ?? 5,
            'slug' => 'pos-customer-api-' . uniqid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $stockId = DB::table('product_stocks')->insertGetId([
            'product_id' => $productId,
            'variant' => '',
            'sku' => 'POS-CUSTOMER-API-' . uniqid(),
            'price' => $attributes['price'] ?? 20,
            'qty' => $attributes['qty'] ?? 5,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ProductStock::query()->with('product')->findOrFail($stockId);
    }

    private function rule(array $attributes = []): LoyaltyRule
    {
        return LoyaltyRule::query()->create(array_merge([
            'name' => 'POS Customer API Rule ' . uniqid(),
            'is_active' => true,
            'earn_rate_amount' => 10,
            'earn_rate_points' => 1,
            'min_order_amount' => 0,
            'currency' => 'USD',
        ], $attributes));
    }

    private function checkoutPayload(ProductStock $stock, string $key, ?int $customerId, float $paidAmount): array
    {
        return [
            'items' => [[
                'product_id' => $stock->product_id,
                'product_stock_id' => $stock->id,
                'quantity' => 1,
            ]],
            'paid_amount' => $paidAmount,
            'pos_request_key' => $key,
            'customer_id' => $customerId,
        ];
    }
}
