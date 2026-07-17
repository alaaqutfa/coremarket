<?php

namespace Tests\Feature;

use App\Models\CashMovement;
use App\Models\Cashbox;
use App\Models\CashierShift;
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

class OperationsPosApiTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithCoreMarketTestSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('coremarket_testing', DB::getDatabaseName());
        $this->assertTrue(Schema::hasTable('cashboxes'));
        $this->assertTrue(Schema::hasColumn('orders', 'pos_request_key'));
        config()->set('coremarket.runtime_snapshot.connection', 'mysql');
        $this->ensureBusinessSettingsTable();
        $this->ensurePermissionTables();
        $this->ensureLegacyUserColumns();
        $this->ensureAdminSupportTables();
        $this->clearPersistedRuntimeFeatures();
        $this->setFeatures(true, true);
    }

    public function test_unauthenticated_operations_pos_endpoints_return_401(): void
    {
        $cashbox = $this->cashbox();
        $shift = CashierShift::query()->create([
            'cashbox_id' => $cashbox->id,
            'opened_by' => $this->user([])->id,
            'status' => 'open',
            'opened_at' => now(),
        ]);
        $receipt = $this->posOrderStub();

        $this->getJson(route('api.v2.operations.pos.session'))->assertUnauthorized();
        $this->getJson(route('api.v2.operations.pos.search', ['q' => 'coffee']))->assertUnauthorized();
        $this->postJson(route('api.v2.operations.pos.checkout'), [])->assertUnauthorized();
        $this->getJson(route('api.v2.operations.pos.receipt', $receipt))->assertUnauthorized();
        $this->getJson(route('api.v2.operations.cashboxes.index'))->assertUnauthorized();
        $this->getJson(route('api.v2.operations.cash_shifts.current'))->assertUnauthorized();
        $this->postJson(route('api.v2.operations.cash_shifts.open', $cashbox), [])->assertUnauthorized();
        $this->postJson(route('api.v2.operations.cash_shifts.close', $shift), [])->assertUnauthorized();
    }

    public function test_operations_login_issues_token_only_for_permitted_staff(): void
    {
        $allowed = $this->user(['pos.view']);
        $denied = $this->user([]);
        $customer = $this->user([], 'customer');

        $this->postJson(route('api.v2.operations.auth.login'), [
            'email_or_phone' => $allowed->email,
            'password' => 'Temporary123!',
            'device_name' => 'cashier-tablet',
        ])->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'user_type']]]);

        $this->postJson(route('api.v2.operations.auth.login'), [
            'email_or_phone' => $denied->email,
            'password' => 'Temporary123!',
        ])->assertForbidden();

        $this->postJson(route('api.v2.operations.auth.login'), [
            'email_or_phone' => $customer->email,
            'password' => 'Temporary123!',
        ])->assertUnauthorized();

        if (Schema::hasColumn('users', 'banned')) {
            $allowed->forceFill(['banned' => 1])->save();
            $this->postJson(route('api.v2.operations.auth.login'), [
                'email_or_phone' => $allowed->email,
                'password' => 'Temporary123!',
            ])->assertUnauthorized();
        }
    }

    public function test_operations_api_features_and_permissions_are_enforced(): void
    {
        $allPermissions = ['pos.view', 'pos.sell', 'pos.receipts.view', 'cashboxes.view', 'cash_shifts.view', 'cash_shifts.open', 'cash_shifts.close'];
        $user = $this->user($allPermissions);
        $cashbox = $this->cashbox();
        $shift = CashierShift::query()->create(['cashbox_id' => $cashbox->id, 'opened_by' => $user->id, 'status' => 'open', 'opened_at' => now()]);
        $receipt = $this->posOrderStub($user);
        Sanctum::actingAs($user, ['operations:pos']);

        $this->setFeatures(false, true);
        foreach ($this->operationsEndpoints($cashbox, $shift, $receipt) as [$method, $url]) {
            $this->{$method}($url)->assertNotFound();
        }

        $this->setFeatures(true, false);
        foreach ($this->operationsEndpoints($cashbox, $shift, $receipt) as [$method, $url]) {
            $this->{$method}($url)->assertNotFound();
        }

        $this->setFeatures(true, true);
        $noPermissions = $this->user([]);
        Sanctum::actingAs($noPermissions, ['operations:pos']);
        $this->getJson(route('api.v2.operations.pos.session'))->assertForbidden();
        $this->getJson(route('api.v2.operations.pos.search', ['q' => 'coffee']))->assertForbidden();
        $this->postJson(route('api.v2.operations.pos.checkout'), [])->assertForbidden();
        $this->getJson(route('api.v2.operations.pos.receipt', $receipt))->assertForbidden();
        $this->getJson(route('api.v2.operations.cashboxes.index'))->assertForbidden();
        $this->postJson(route('api.v2.operations.cash_shifts.open', $cashbox), [])->assertForbidden();
        $this->postJson(route('api.v2.operations.cash_shifts.close', $shift), [])->assertForbidden();

        $viewOnly = $this->user(['pos.view']);
        Sanctum::actingAs($viewOnly, ['operations:pos']);
        $this->getJson(route('api.v2.operations.cash_shifts.current'))
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_session_and_search_return_expected_json_payloads(): void
    {
        $user = $this->user(['pos.view']);
        Sanctum::actingAs($user, ['operations:pos']);

        $this->getJson(route('api.v2.operations.pos.session'))
            ->assertOk()
            ->assertJsonPath('data.has_open_shift', false)
            ->assertJsonPath('data.shift', null);

        $shift = $this->openShift($user, 15);
        $this->getJson(route('api.v2.operations.pos.session'))
            ->assertOk()
            ->assertJsonPath('data.has_open_shift', true)
            ->assertJsonPath('data.shift.id', $shift->id)
            ->assertJsonPath('data.cashbox.id', $shift->cashbox_id)
            ->assertJsonPath('data.expected_cash', 15);

        $stock = $this->productStock($user, [
            'name' => 'API Search Coffee',
            'product_barcode' => 'API-PRODUCT-' . uniqid(),
            'variant_barcode' => 'API-VARIANT-' . uniqid(),
            'sku' => 'API-SKU-' . uniqid(),
        ]);

        foreach ([$stock->barcode, $stock->product->barcode, $stock->sku, 'Search Coffee'] as $query) {
            $this->getJson(route('api.v2.operations.pos.search', ['q' => $query]))
                ->assertOk()
                ->assertJsonPath('ok', true)
                ->assertJsonPath('data.items.0.product_stock_id', $stock->id);
        }

        $this->getJson(route('api.v2.operations.pos.search'))->assertUnprocessable();
        $this->getJson(route('api.v2.operations.pos.search', ['q' => str_repeat('x', 101)]))->assertUnprocessable();
    }

    public function test_checkout_is_safe_idempotent_and_does_not_create_journals(): void
    {
        $user = $this->user(['pos.sell']);
        Sanctum::actingAs($user, ['operations:pos']);
        $stock = $this->productStock($user, ['qty' => 5, 'price' => 20, 'purchase_price' => 10]);
        $key = 'api-checkout-' . uniqid();

        $this->postJson(route('api.v2.operations.pos.checkout'), $this->checkoutPayload($stock, $key, 1, 20))
            ->assertConflict();

        $shift = $this->openShift($user);
        $journalCount = DB::table('journal_entries')->count();
        $payload = $this->checkoutPayload($stock, $key, 2, 50);
        $response = $this->postJson(route('api.v2.operations.pos.checkout'), $payload)
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', 'POS order created');
        $orderId = $response->json('data.order_id');
        $order = Order::query()->findOrFail($orderId);

        $this->assertSame('pos', $order->order_from);
        $this->assertSame('cash', $order->payment_type);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame($user->id, $order->cashier_id);
        $this->assertSame($shift->id, $order->cashier_shift_id);
        $this->assertNotEmpty($order->pos_receipt_number);
        $this->assertSame($key, $order->pos_request_key);
        $this->assertSame('50.000000', $order->paid_amount);
        $this->assertSame('10.000000', $order->change_amount);
        $this->assertDatabaseHas('order_details', ['order_id' => $order->id, 'cost_price' => '10.000000', 'total_cost' => '20.000000', 'profit_amount' => '20.000000']);
        $this->assertSame(3, (int) $stock->fresh()->qty);
        $this->assertDatabaseHas('inventory_movements', ['order_id' => $order->id, 'movement_type' => 'sale', 'direction' => 'out']);
        $this->assertDatabaseHas('cash_movements', ['reference_type' => Order::class, 'reference_id' => $order->id, 'movement_type' => 'sale', 'direction' => 'in']);
        $this->assertSame($journalCount, DB::table('journal_entries')->count());

        $this->postJson(route('api.v2.operations.pos.checkout'), $payload)
            ->assertCreated()
            ->assertJsonPath('data.order_id', $order->id);
        $this->assertSame(3, (int) $stock->fresh()->qty);
        $this->assertSame(1, DB::table('inventory_movements')->where('order_id', $order->id)->where('movement_type', 'sale')->count());
        $this->assertSame(1, CashMovement::query()->where('reference_type', Order::class)->where('reference_id', $order->id)->where('movement_type', 'sale')->count());

        $this->postJson(route('api.v2.operations.pos.checkout'), $this->checkoutPayload($stock, 'api-oversell-' . uniqid(), 4, 100))->assertConflict();
        $this->postJson(route('api.v2.operations.pos.checkout'), $this->checkoutPayload($stock, 'api-underpaid-' . uniqid(), 1, 1))->assertUnprocessable();
        $this->postJson(route('api.v2.operations.pos.checkout'), ['items' => []])->assertUnprocessable();
    }

    public function test_receipts_are_pos_only_and_limited_to_the_cashier_or_admin(): void
    {
        $owner = $this->user(['pos.sell', 'pos.receipts.view']);
        $this->openShift($owner);
        $stock = $this->productStock($owner);
        $order = app(WebPosService::class)->createPosOrder($this->checkoutPayload($stock, 'receipt-' . uniqid())['items'], ['payment_type' => 'cash', 'paid_amount' => 50], $owner, 'receipt-' . uniqid());

        Sanctum::actingAs($owner, ['operations:pos']);
        $this->getJson(route('api.v2.operations.pos.receipt', $order))
            ->assertOk()
            ->assertJsonPath('data.receipt_number', $order->pos_receipt_number)
            ->assertJsonPath('data.cashier.id', $owner->id)
            ->assertJsonPath('data.shift_id', $order->cashier_shift_id)
            ->assertJsonCount(1, 'data.items');

        $storefrontId = DB::table('orders')->insertGetId([
            'shipping_type' => 'home_delivery',
            'order_from' => 'web',
            'date' => now()->timestamp,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $storefront = Order::query()->findOrFail($storefrontId);
        $this->getJson(route('api.v2.operations.pos.receipt', $storefront))->assertNotFound();

        $otherStaff = $this->user(['pos.receipts.view']);
        Sanctum::actingAs($otherStaff, ['operations:pos']);
        $this->getJson(route('api.v2.operations.pos.receipt', $order))->assertForbidden();

        $admin = $this->user([], 'admin');
        Sanctum::actingAs($admin, ['operations:pos']);
        $this->getJson(route('api.v2.operations.pos.receipt', $order))->assertOk();
    }

    private function operationsEndpoints(Cashbox $cashbox, CashierShift $shift, Order $receipt): array
    {
        return [
            ['getJson', route('api.v2.operations.pos.session')],
            ['getJson', route('api.v2.operations.pos.search', ['q' => 'coffee'])],
            ['postJson', route('api.v2.operations.pos.checkout')],
            ['getJson', route('api.v2.operations.pos.receipt', $receipt)],
            ['getJson', route('api.v2.operations.cashboxes.index')],
            ['getJson', route('api.v2.operations.cash_shifts.current')],
            ['postJson', route('api.v2.operations.cash_shifts.open', $cashbox)],
            ['postJson', route('api.v2.operations.cash_shifts.close', $shift)],
        ];
    }

    private function setFeatures(bool $pos, bool $cashbox): void
    {
        config()->set('coremarket.features.pos', $pos);
        config()->set('coremarket.features.cashbox_shifts', $cashbox);
        app()->forgetInstance(CoreMarketFeatureAccessService::class);
    }

    private function clearPersistedRuntimeFeatures(): void
    {
        DB::table('business_settings')
            ->where('type', 'coremarket_runtime_features')
            ->delete();

        Cache::forget('business_settings');
        app()->forgetInstance(CoreMarketFeatureAccessService::class);
    }

    private function user(array $permissions, string $type = 'staff'): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::query()->firstOrCreate(['name' => 'Operations POS API ' . uniqid(), 'guard_name' => 'web']);
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }

        $user = new User();
        $user->forceFill([
            'name' => 'Operations POS API User',
            'email' => uniqid('operations-pos-api') . '@example.test',
            'password' => bcrypt('Temporary123!'),
            'user_type' => $type,
            'email_verified_at' => now(),
        ])->save();
        $user->assignRole($role);

        return $user;
    }

    private function cashbox(array $attributes = []): Cashbox
    {
        return app(CashboxService::class)->createCashbox(array_merge([
            'name' => 'Operations POS API Cashbox',
            'code' => 'API-CASH-' . uniqid(),
            'currency' => 'USD',
            'status' => 'active',
        ], $attributes));
    }

    private function openShift(User $user, float $opening = 0): CashierShift
    {
        return app(CashboxService::class)->openShift($this->cashbox(), $user, $opening);
    }

    private function productStock(User $user, array $attributes = []): ProductStock
    {
        $now = now();
        $productId = DB::table('products')->insertGetId([
            'name' => $attributes['name'] ?? 'Operations POS API Product ' . uniqid(),
            'user_id' => $user->id,
            'category_id' => 1,
            'unit_price' => $attributes['price'] ?? 20,
            'purchase_price' => $attributes['purchase_price'] ?? 10,
            'current_stock' => $attributes['qty'] ?? 5,
            'barcode' => $attributes['product_barcode'] ?? null,
            'slug' => 'operations-pos-api-' . uniqid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $stockId = DB::table('product_stocks')->insertGetId([
            'product_id' => $productId,
            'variant' => '',
            'sku' => $attributes['sku'] ?? 'API-SKU-' . uniqid(),
            'barcode' => $attributes['variant_barcode'] ?? null,
            'price' => $attributes['price'] ?? 20,
            'qty' => $attributes['qty'] ?? 5,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ProductStock::query()->with('product')->findOrFail($stockId);
    }

    private function checkoutPayload(ProductStock $stock, string $key, int $quantity = 1, float $paidAmount = 50): array
    {
        return [
            'items' => [[
                'product_id' => $stock->product_id,
                'product_stock_id' => $stock->id,
                'quantity' => $quantity,
            ]],
            'paid_amount' => $paidAmount,
            'pos_request_key' => $key,
        ];
    }

    private function posOrderStub(?User $cashier = null): Order
    {
        $id = DB::table('orders')->insertGetId([
            'order_from' => 'pos',
            'shipping_type' => 'pos',
            'payment_type' => 'cash',
            'payment_status' => 'paid',
            'cashier_id' => $cashier?->id,
            'pos_receipt_number' => 'POS-API-' . uniqid(),
            'pos_request_key' => 'POS-API-REQ-' . uniqid(),
            'date' => now()->timestamp,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Order::query()->findOrFail($id);
    }
}
