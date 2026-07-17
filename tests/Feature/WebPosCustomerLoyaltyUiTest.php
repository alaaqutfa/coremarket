<?php

namespace Tests\Feature;

use App\Models\LoyaltyPointMovement;
use App\Models\LoyaltyRule;
use App\Models\Order;
use App\Models\ProductStock;
use App\Models\User;
use App\Services\CashboxService;
use App\Services\CoreMarketFeatureAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\InteractsWithCoreMarketTestSchema;
use Tests\TestCase;

class WebPosCustomerLoyaltyUiTest extends TestCase
{
    use InteractsWithCoreMarketTestSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('coremarket_testing', DB::getDatabaseName());
        $this->assertTrue(Schema::hasTable('loyalty_accounts'));
        $this->ensureBusinessSettingsTable();
        $this->ensurePermissionTables();
        $this->ensureLegacyUserColumns();
        $this->ensureAdminSupportTables();
        $this->setFeatures(true, true, true);
    }

    public function test_customer_search_requires_pos_view_permission(): void
    {
        DB::beginTransaction();

        try {
            $withoutView = $this->staff([]);
            $this->actingAs($withoutView)->getJson(route('operations.pos.customers.search', ['q' => 'Al']))->assertForbidden();

        } finally {
            DB::rollBack();
        }
    }

    public function test_customer_search_is_blocked_when_pos_feature_is_disabled(): void
    {
        DB::beginTransaction();

        try {
            $this->setFeatures(false, true, true);
            $user = $this->staff(['pos.view']);
            $this->actingAs($user)->getJson(route('operations.pos.customers.search', ['q' => 'Al']))->assertNotFound();
        } finally {
            DB::rollBack();
        }

    }

    public function test_customer_search_is_blocked_when_cashbox_feature_is_disabled(): void
    {
        DB::beginTransaction();

        try {
            $this->setFeatures(true, false, true);
            $user = $this->staff(['pos.view']);
            $this->actingAs($user)->getJson(route('operations.pos.customers.search', ['q' => 'Al']))->assertNotFound();
        } finally {
            DB::rollBack();
        }
    }

    public function test_customer_search_returns_only_active_customers_and_page_renders_walk_in_ui(): void
    {
        DB::beginTransaction();

        try {
            $staff = $this->staff(['pos.view']);
            $customer = $this->customer(['name' => 'Alice POS', 'phone' => '550001']);
            $this->customer(['name' => 'Alice Blocked', 'banned' => 1]);
            $this->staff([], ['name' => 'Alice Staff']);

            $this->actingAs($staff)->getJson(route('operations.pos.customers.search', ['q' => 'Alice']))
                ->assertOk()
                ->assertJsonCount(1, 'items')
                ->assertJsonPath('items.0.id', $customer->id)
                ->assertJsonMissing(['email' => $customer->email]);

            $this->actingAs($staff)->get(route('operations.pos'))
                ->assertOk()
                ->assertSee('Walk-in customer')
                ->assertSee('Search customer by name or phone')
                ->assertSee('pos-customer-id', false);
        } finally {
            DB::rollBack();
        }
    }

    public function test_checkout_with_customer_stores_customer_and_receipt_shows_loyalty_without_side_effects(): void
    {
        DB::beginTransaction();

        try {
            $staff = $this->staff(['pos.view', 'pos.sell', 'pos.receipts.view']);
            $this->openShift($staff);
            $stock = $this->productStock($staff, ['price' => 20]);
            $customer = $this->customer(['name' => 'Receipt Customer', 'phone' => '550002']);
            $this->rule(['applies_to_order_from' => 'pos', 'earn_rate_amount' => 10, 'earn_rate_points' => 2]);
            $walletsBefore = DB::table('wallets')->count();
            $journalsBefore = DB::table('journal_entries')->count();
            $key = 'web-ui-customer-' . uniqid();

            $this->actingAs($staff)->post(route('operations.pos.checkout'), $this->checkoutPayload($stock, $key, $customer->id, 30))
                ->assertRedirect();

            $order = Order::query()->where('pos_request_key', $key)->firstOrFail();
            $this->assertSame($customer->id, $order->user_id);
            $this->assertDatabaseHas('loyalty_point_movements', ['reference_id' => $order->id, 'movement_type' => 'earn', 'points' => 4]);
            $this->actingAs($staff)->get(route('operations.pos.receipt', $order))
                ->assertOk()
                ->assertSee('Receipt Customer')
                ->assertSee('Points earned')
                ->assertSee('Balance after');
            $this->assertSame($walletsBefore, DB::table('wallets')->count());
            $this->assertSame($journalsBefore, DB::table('journal_entries')->count());
        } finally {
            DB::rollBack();
        }
    }

    public function test_walk_in_checkout_still_works_and_invalid_customer_is_rejected_without_order(): void
    {
        DB::beginTransaction();

        try {
            $staff = $this->staff(['pos.view', 'pos.sell']);
            $this->openShift($staff);
            $stock = $this->productStock($staff, ['qty' => 3]);
            $walkInKey = 'web-ui-walk-in-' . uniqid();

            $this->actingAs($staff)->post(route('operations.pos.checkout'), $this->checkoutPayload($stock, $walkInKey, null, 50))
                ->assertRedirect();
            $this->assertNull(Order::query()->where('pos_request_key', $walkInKey)->value('user_id'));

            $ordersBefore = Order::query()->count();
            $this->actingAs($staff)->post(route('operations.pos.checkout'), $this->checkoutPayload($stock, 'web-ui-invalid-' . uniqid(), $staff->id, 50))
                ->assertSessionHasErrors('pos');
            $this->assertSame($ordersBefore, Order::query()->count());
        } finally {
            DB::rollBack();
        }
    }

    public function test_loyalty_display_is_hidden_when_disabled_while_customer_selection_remains_available(): void
    {
        DB::beginTransaction();

        try {
            $staff = $this->staff(['pos.view', 'pos.sell']);
            $customer = $this->customer(['name' => 'Disabled Loyalty Customer']);
            $this->setFeatures(true, true, false);

            $this->actingAs($staff)->get(route('operations.pos'))
                ->assertOk()
                ->assertSee('Search customer by name or phone')
                ->assertDontSee('Loyalty balance');
            $this->actingAs($staff)->getJson(route('operations.pos.customers.search', ['q' => 'Disabled']))
                ->assertOk()
                ->assertJsonPath('items.0.id', $customer->id)
                ->assertJsonPath('items.0.loyalty', null);
        } finally {
            DB::rollBack();
        }
    }

    private function setFeatures(bool $pos, bool $cashbox, bool $loyalty): void
    {
        config()->set('coremarket.features.pos', $pos);
        config()->set('coremarket.features.cashbox_shifts', $cashbox);
        config()->set('coremarket.features.loyalty_points', $loyalty);
        app()->forgetInstance(CoreMarketFeatureAccessService::class);

        $features = Mockery::mock(CoreMarketFeatureAccessService::class);
        $features->shouldReceive('enabled')->andReturnUsing(function (string $feature) use ($pos, $cashbox, $loyalty): bool {
            return match ($feature) {
                'pos' => $pos,
                'cashbox_shifts' => $cashbox,
                'loyalty_points' => $loyalty,
                default => false,
            };
        });
        app()->instance(CoreMarketFeatureAccessService::class, $features);
    }

    private function staff(array $permissions, array $attributes = []): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::query()->firstOrCreate(['name' => 'Web POS Customer UI ' . uniqid(), 'guard_name' => 'web']);
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }

        return $this->user('staff', $attributes, $role);
    }

    private function customer(array $attributes = []): User
    {
        return $this->user('customer', $attributes);
    }

    private function user(string $type, array $attributes = [], ?Role $role = null): User
    {
        $user = new User();
        $user->forceFill(array_merge([
            'name' => 'Web POS Customer ' . ucfirst($type) . ' ' . uniqid(),
            'email' => uniqid('web-pos-customer-' . $type) . '@example.test',
            'phone' => '550' . random_int(100000, 999999),
            'password' => bcrypt('Temporary123!'),
            'user_type' => $type,
            'banned' => 0,
            'email_verified_at' => now(),
        ], $attributes))->save();
        if ($role) {
            $user->assignRole($role);
        }

        return $user;
    }

    private function openShift(User $staff): void
    {
        $cashbox = app(CashboxService::class)->createCashbox([
            'name' => 'Web POS Customer UI Cashbox',
            'code' => 'WEB-POS-CUSTOMER-' . uniqid(),
            'currency' => 'USD',
            'status' => 'active',
        ]);
        app(CashboxService::class)->openShift($cashbox, $staff, 0);
    }

    private function productStock(User $staff, array $attributes = []): ProductStock
    {
        $now = now();
        $productId = DB::table('products')->insertGetId([
            'name' => 'Web POS Customer Product ' . uniqid(),
            'user_id' => $staff->id,
            'category_id' => 1,
            'unit_price' => $attributes['price'] ?? 20,
            'purchase_price' => 10,
            'current_stock' => $attributes['qty'] ?? 5,
            'slug' => 'web-pos-customer-' . uniqid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $stockId = DB::table('product_stocks')->insertGetId([
            'product_id' => $productId,
            'variant' => '',
            'sku' => 'WEB-POS-CUSTOMER-' . uniqid(),
            'price' => $attributes['price'] ?? 20,
            'qty' => $attributes['qty'] ?? 5,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ProductStock::query()->findOrFail($stockId);
    }

    private function checkoutPayload(ProductStock $stock, string $key, ?int $customerId, float $paidAmount): array
    {
        return [
            'items' => [[
                'product_id' => $stock->product_id,
                'product_stock_id' => $stock->id,
                'quantity' => 1,
            ]],
            'customer_id' => $customerId,
            'paid_amount' => $paidAmount,
            'pos_request_key' => $key,
        ];
    }

    private function rule(array $attributes = []): LoyaltyRule
    {
        return LoyaltyRule::query()->create(array_merge([
            'name' => 'Web POS customer loyalty rule ' . uniqid(),
            'is_active' => true,
            'earn_rate_amount' => 10,
            'earn_rate_points' => 1,
            'min_order_amount' => 0,
            'currency' => 'USD',
        ], $attributes));
    }
}
