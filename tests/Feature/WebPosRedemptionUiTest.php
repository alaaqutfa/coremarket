<?php

namespace Tests\Feature;

use App\Models\LoyaltyAccount;
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

class WebPosRedemptionUiTest extends TestCase
{
    use InteractsWithCoreMarketTestSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('coremarket_testing', DB::getDatabaseName());
        $this->assertTrue(Schema::hasColumn('orders', 'loyalty_points_redeemed'));
        $this->ensureBusinessSettingsTable();
        $this->ensurePermissionTables();
        $this->ensureLegacyUserColumns();
        $this->ensureAdminSupportTables();
        $this->setFeatures(true);
    }

    public function test_redemption_controls_start_hidden_for_walk_in_and_are_available_to_a_selected_customer_with_points(): void
    {
        DB::beginTransaction();

        try {
            $staff = $this->staff(['pos.view']);
            $customer = $this->customer(['name' => 'Redemption UI Customer']);
            LoyaltyAccount::query()->create(['user_id' => $customer->id, 'points_balance' => 30]);

            $this->actingAs($staff)->get(route('operations.pos'))
                ->assertOk()
                ->assertSee('pos-loyalty-redemption', false)
                ->assertSee('d-none', false)
                ->assertSee('Redeem points for this POS sale')
                ->assertSee('Discount will be calculated at checkout.');

            $this->actingAs($staff)->getJson(route('operations.pos.customers.search', ['q' => 'Redemption']))
                ->assertOk()
                ->assertJsonPath('items.0.id', $customer->id)
                ->assertJsonPath('items.0.loyalty.balance', 30);
        } finally {
            DB::rollBack();
        }
    }

    public function test_checkout_applies_redemption_and_receipt_shows_the_historical_summary_without_side_effects(): void
    {
        DB::beginTransaction();

        try {
            $staff = $this->staff(['pos.view', 'pos.sell', 'pos.receipts.view', 'pos.redeem_loyalty']);
            $this->openShift($staff);
            $stock = $this->productStock($staff, 20);
            $customer = $this->customer();
            LoyaltyAccount::query()->create(['user_id' => $customer->id, 'points_balance' => 30]);
            $this->rule();
            $walletsBefore = DB::table('wallets')->count();
            $journalsBefore = DB::table('journal_entries')->count();
            $key = 'web-pos-redemption-ui-' . uniqid();

            $this->actingAs($staff)->post(route('operations.pos.checkout'), $this->checkoutPayload($stock, $key, $customer->id, 20, 17))
                ->assertRedirect();

            $order = Order::query()->where('pos_request_key', $key)->firstOrFail();
            $this->assertSame(20, (int) $order->loyalty_points_redeemed);
            $this->assertSame(4.0, (float) $order->loyalty_redemption_discount);
            $this->assertSame(16.0, (float) $order->grand_total);
            $this->actingAs($staff)->get(route('operations.pos.receipt', $order))
                ->assertOk()
                ->assertSee('Points redeemed')
                ->assertSee('Redemption discount')
                ->assertSee('Final total');
            $this->assertSame($walletsBefore, DB::table('wallets')->count());
            $this->assertSame($journalsBefore, DB::table('journal_entries')->count());
        } finally {
            DB::rollBack();
        }
    }

    public function test_invalid_redemption_does_not_create_an_order_and_loyalty_disabled_hides_controls_without_blocking_customer_sales(): void
    {
        DB::beginTransaction();

        try {
            $staff = $this->staff(['pos.view', 'pos.sell', 'pos.receipts.view', 'pos.redeem_loyalty']);
            $this->openShift($staff);
            $stock = $this->productStock($staff, 20);
            $customer = $this->customer();
            $ordersBefore = Order::query()->count();

            $this->actingAs($staff)->post(route('operations.pos.checkout'), $this->checkoutPayload($stock, 'web-pos-redemption-invalid-' . uniqid(), $customer->id, 10, 20))
                ->assertSessionHasErrors('pos');
            $this->assertSame($ordersBefore, Order::query()->count());

            $this->setFeatures(false);
            $this->actingAs($staff)->get(route('operations.pos'))
                ->assertOk()
                ->assertDontSee('name="points_to_redeem"', false);
            $normalKey = 'web-pos-redemption-disabled-' . uniqid();
            $this->actingAs($staff)->post(route('operations.pos.checkout'), $this->checkoutPayload($stock, $normalKey, $customer->id, 0, 20))
                ->assertRedirect();
            $normalOrder = Order::query()->where('pos_request_key', $normalKey)->firstOrFail();
            $this->actingAs($staff)->get(route('operations.pos.receipt', $normalOrder))
                ->assertOk()
                ->assertDontSee('Points redeemed');
        } finally {
            DB::rollBack();
        }
    }

    public function test_redemption_requires_the_dedicated_permission_but_normal_checkout_does_not(): void
    {
        DB::beginTransaction();

        try {
            $staff = $this->staff(['pos.sell']);
            $this->openShift($staff);
            $stock = $this->productStock($staff, 20);
            $customer = $this->customer();
            LoyaltyAccount::query()->create(['user_id' => $customer->id, 'points_balance' => 30]);
            $this->rule();
            $ordersBefore = Order::query()->count();

            $this->actingAs($staff)->post(route('operations.pos.checkout'), $this->checkoutPayload(
                $stock,
                'web-pos-redemption-forbidden-' . uniqid(),
                $customer->id,
                10,
                20
            ))->assertForbidden();
            $this->assertSame($ordersBefore, Order::query()->count());

            $normalKey = 'web-pos-no-redemption-permission-' . uniqid();
            $this->actingAs($staff)->post(route('operations.pos.checkout'), $this->checkoutPayload(
                $stock,
                $normalKey,
                $customer->id,
                0,
                20
            ))->assertRedirect();
            $this->assertDatabaseHas('orders', ['pos_request_key' => $normalKey]);
        } finally {
            DB::rollBack();
        }
    }

    private function setFeatures(bool $loyalty): void
    {
        config()->set('coremarket.features.pos', true);
        config()->set('coremarket.features.cashbox_shifts', true);
        config()->set('coremarket.features.loyalty_points', $loyalty);
        app()->forgetInstance(CoreMarketFeatureAccessService::class);

        $features = Mockery::mock(CoreMarketFeatureAccessService::class);
        $features->shouldReceive('enabled')->andReturnUsing(fn (string $feature): bool => match ($feature) {
            'pos', 'cashbox_shifts' => true,
            'loyalty_points' => $loyalty,
            default => false,
        });
        app()->instance(CoreMarketFeatureAccessService::class, $features);
    }

    private function staff(array $permissions): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::query()->firstOrCreate(['name' => 'Web POS Redemption UI ' . uniqid(), 'guard_name' => 'web']);
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }

        return $this->user('staff', $role);
    }

    private function customer(array $attributes = []): User
    {
        return $this->user('customer', null, $attributes);
    }

    private function user(string $type, ?Role $role = null, array $attributes = []): User
    {
        $user = new User();
        $user->forceFill(array_merge([
            'name' => 'Web POS Redemption ' . ucfirst($type) . ' ' . uniqid(),
            'email' => uniqid('web-pos-redemption-' . $type) . '@example.test',
            'phone' => '555' . random_int(100000, 999999),
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
            'name' => 'Web POS Redemption Cashbox',
            'code' => 'WEB-POS-REDEMPTION-' . uniqid(),
            'currency' => 'USD',
            'status' => 'active',
        ]);
        app(CashboxService::class)->openShift($cashbox, $staff, 0);
    }

    private function productStock(User $staff, float $price): ProductStock
    {
        $now = now();
        $productId = DB::table('products')->insertGetId([
            'name' => 'Web POS Redemption Product ' . uniqid(),
            'user_id' => $staff->id,
            'category_id' => 1,
            'unit_price' => $price,
            'purchase_price' => 10,
            'current_stock' => 5,
            'slug' => 'web-pos-redemption-' . uniqid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $stockId = DB::table('product_stocks')->insertGetId([
            'product_id' => $productId,
            'variant' => '',
            'sku' => 'WEB-POS-REDEMPTION-' . uniqid(),
            'price' => $price,
            'qty' => 5,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ProductStock::query()->findOrFail($stockId);
    }

    private function rule(): LoyaltyRule
    {
        return LoyaltyRule::query()->create([
            'name' => 'Web POS Redemption Rule ' . uniqid(),
            'is_active' => true,
            'earn_rate_amount' => 10,
            'earn_rate_points' => 1,
            'min_order_amount' => 0,
            'currency' => 'USD',
            'applies_to_order_from' => 'pos',
            'redeem_points' => 10,
            'redeem_value' => 2,
            'min_redeem_points' => 10,
            'allow_pos_redeem' => true,
        ]);
    }

    private function checkoutPayload(ProductStock $stock, string $key, ?int $customerId, int $pointsToRedeem, float $paidAmount): array
    {
        return [
            'items' => [[
                'product_id' => $stock->product_id,
                'product_stock_id' => $stock->id,
                'quantity' => 1,
            ]],
            'customer_id' => $customerId,
            'points_to_redeem' => $pointsToRedeem,
            'paid_amount' => $paidAmount,
            'pos_request_key' => $key,
        ];
    }
}
