<?php

namespace Tests\Feature;

use App\Models\CashMovement;
use App\Models\Order;
use App\Models\ProductStock;
use App\Models\User;
use App\Services\CashboxService;
use App\Services\CoreMarketFeatureAccessService;
use Database\Seeders\OperationsPermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\InteractsWithCoreMarketTestSchema;
use Tests\TestCase;

class WebPosUiTest extends TestCase
{
    use InteractsWithCoreMarketTestSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('coremarket_testing', DB::getDatabaseName());
        $this->assertTrue(Schema::hasColumn('orders', 'pos_request_key'));
        $this->assertTrue(Schema::hasTable('cashboxes'));
        $this->assertTrue(Schema::hasTable('cashier_shifts'));

        config()->set('coremarket.runtime_snapshot.connection', 'mysql');
        $this->ensureBusinessSettingsTable();
        $this->ensurePermissionTables();
        $this->ensureLegacyUserColumns();
        $this->ensureAdminSupportTables();
        $this->setFeatures(true, true);
    }

    public function test_disabled_pos_feature_hides_navigation_and_blocks_every_direct_url(): void
    {
        DB::beginTransaction();

        try {
            $user = $this->user(['pos.view', 'pos.sell', 'pos.receipts.view']);
            $receipt = $this->posOrder();
            $this->setFeatures(false, true);

            $this->actingAs($user);
            $this->assertStringNotContainsString(route('operations.pos'), view('backend.inc.admin_sidenav')->render());
            $this->get(route('operations.pos'))->assertNotFound();
            $this->get(route('operations.pos.search', ['q' => 'coffee']))->assertNotFound();
            $this->post(route('operations.pos.checkout'), [])->assertNotFound();
            $this->get(route('operations.pos.receipt', $receipt))->assertNotFound();
        } finally {
            DB::rollBack();
        }
    }

    public function test_disabled_cashbox_feature_hides_pos_and_blocks_direct_urls(): void
    {
        DB::beginTransaction();

        try {
            $user = $this->user(['pos.view', 'pos.sell', 'pos.receipts.view']);
            $receipt = $this->posOrder();
            $this->setFeatures(true, false);

            $this->actingAs($user);
            $this->assertStringNotContainsString(route('operations.pos'), view('backend.inc.admin_sidenav')->render());
            $this->get(route('operations.pos'))->assertNotFound();
            $this->get(route('operations.pos.search', ['q' => 'coffee']))->assertNotFound();
            $this->post(route('operations.pos.checkout'), [])->assertNotFound();
            $this->get(route('operations.pos.receipt', $receipt))->assertNotFound();
        } finally {
            DB::rollBack();
        }
    }

    public function test_missing_permissions_hide_navigation_and_protect_each_action(): void
    {
        DB::beginTransaction();

        try {
            $withoutView = $this->user([]);
            $this->actingAs($withoutView)->get(route('operations.pos'))->assertForbidden();
            $this->assertStringNotContainsString(route('operations.pos'), view('backend.inc.admin_sidenav')->render());

            $viewOnly = $this->user(['pos.view']);
            $this->actingAs($viewOnly)->get(route('operations.pos'))->assertOk()->assertSee('Web POS');
            $this->actingAs($viewOnly)->post(route('operations.pos.checkout'), [])->assertForbidden();

            $receipt = $this->posOrder();
            $this->actingAs($viewOnly)->get(route('operations.pos.receipt', $receipt))->assertForbidden();
        } finally {
            DB::rollBack();
        }
    }

    public function test_pos_permissions_are_seeded_for_the_operations_role_ui(): void
    {
        DB::beginTransaction();

        try {
            $this->seed(OperationsPermissionSeeder::class);

            foreach (['pos.view', 'pos.sell', 'pos.receipts.view', 'pos.redeem_loyalty'] as $permission) {
                $this->assertDatabaseHas('permissions', ['name' => $permission, 'section' => 'operations']);
            }
        } finally {
            DB::rollBack();
        }
    }

    public function test_pos_page_warns_when_the_cashier_has_no_open_shift(): void
    {
        DB::beginTransaction();

        try {
            $user = $this->user(['pos.view']);

            $this->actingAs($user)->get(route('operations.pos'))
                ->assertOk()
                ->assertSee('Open a cashier shift before completing a POS sale.');
        } finally {
            DB::rollBack();
        }
    }

    public function test_search_endpoint_supports_barcode_sku_and_name(): void
    {
        DB::beginTransaction();

        try {
            $user = $this->user(['pos.view']);
            $stock = $this->productStock($user, [
                'name' => 'UI Search Coffee',
                'product_barcode' => 'UI-PRODUCT-' . uniqid(),
                'variant_barcode' => 'UI-VARIANT-' . uniqid(),
                'sku' => 'UI-SKU-' . uniqid(),
            ]);

            $this->actingAs($user)->getJson(route('operations.pos.search', ['q' => $stock->barcode]))
                ->assertOk()->assertJsonPath('0.matched_by', 'variant_barcode');
            $this->actingAs($user)->getJson(route('operations.pos.search', ['q' => $stock->sku]))
                ->assertOk()->assertJsonPath('0.matched_by', 'sku');
            $this->actingAs($user)->getJson(route('operations.pos.search', ['q' => 'Search Coffee']))
                ->assertOk()->assertJsonPath('0.matched_by', 'name');
        } finally {
            DB::rollBack();
        }
    }

    public function test_checkout_creates_pos_order_redirects_to_receipt_and_records_safe_movements(): void
    {
        DB::beginTransaction();

        try {
            $user = $this->user(['pos.view', 'pos.sell', 'pos.receipts.view']);
            $this->openShift($user);
            $stock = $this->productStock($user, ['qty' => 5, 'price' => 20, 'purchase_price' => 10]);
            $journalCount = DB::table('journal_entries')->count();
            $key = 'ui-checkout-' . uniqid();

            $this->actingAs($user)->post(route('operations.pos.checkout'), $this->checkoutPayload($stock, $key, 2, 50))
                ->assertRedirect();

            $order = Order::query()->where('pos_request_key', $key)->firstOrFail();
            $this->assertSame('pos', $order->order_from);
            $this->assertSame('paid', $order->payment_status);
            $this->assertSame(3, (int) $stock->fresh()->qty);
            $this->assertDatabaseHas('inventory_movements', ['order_id' => $order->id, 'movement_type' => 'sale', 'direction' => 'out']);
            $this->assertDatabaseHas('cash_movements', ['reference_type' => Order::class, 'reference_id' => $order->id, 'movement_type' => 'sale', 'direction' => 'in']);
            $this->assertSame($journalCount, DB::table('journal_entries')->count());

            $this->actingAs($user)->get(route('operations.pos.receipt', $order))
                ->assertOk()
                ->assertSee($order->pos_receipt_number)
                ->assertSee('Paid')
                ->assertSee('Change');
        } finally {
            DB::rollBack();
        }
    }

    public function test_checkout_requires_an_open_shift_and_prevents_oversell(): void
    {
        DB::beginTransaction();

        try {
            $user = $this->user(['pos.view', 'pos.sell']);
            $stock = $this->productStock($user, ['qty' => 1]);

            $this->actingAs($user)->post(route('operations.pos.checkout'), $this->checkoutPayload($stock, 'ui-no-shift-' . uniqid()))
                ->assertSessionHasErrors('pos');

            $this->openShift($user);
            $this->actingAs($user)->post(route('operations.pos.checkout'), $this->checkoutPayload($stock, 'ui-oversell-' . uniqid(), 2, 50))
                ->assertSessionHasErrors('pos');
            $this->assertSame(1, (int) $stock->fresh()->qty);
        } finally {
            DB::rollBack();
        }
    }

    public function test_duplicate_request_key_does_not_duplicate_order_stock_or_cash_movement(): void
    {
        DB::beginTransaction();

        try {
            $user = $this->user(['pos.view', 'pos.sell', 'pos.receipts.view']);
            $this->openShift($user);
            $stock = $this->productStock($user, ['qty' => 5]);
            $key = 'ui-duplicate-' . uniqid();
            $payload = $this->checkoutPayload($stock, $key, 2, 50);

            $this->actingAs($user)->post(route('operations.pos.checkout'), $payload)->assertRedirect();
            $this->actingAs($user)->post(route('operations.pos.checkout'), $payload)->assertRedirect();

            $order = Order::query()->where('pos_request_key', $key)->firstOrFail();
            $this->assertSame(1, Order::query()->where('pos_request_key', $key)->count());
            $this->assertSame(3, (int) $stock->fresh()->qty);
            $this->assertSame(1, DB::table('inventory_movements')->where('order_id', $order->id)->where('movement_type', 'sale')->count());
            $this->assertSame(1, CashMovement::query()->where('reference_type', Order::class)->where('reference_id', $order->id)->where('movement_type', 'sale')->count());
        } finally {
            DB::rollBack();
        }
    }

    public function test_sidebar_places_pos_inside_operations_with_active_routes(): void
    {
        DB::beginTransaction();

        try {
            $user = $this->user(['pos.view', 'pos.receipts.view']);
            $order = $this->posOrder();

            $index = $this->actingAs($user)->get(route('operations.pos'));
            $index->assertOk()->assertSee('Operations')->assertSee(route('operations.pos'));
            $this->assertStringContainsString('operations.pos.receipt', file_get_contents(resource_path('views/backend/inc/admin_sidenav.blade.php')));

            $this->actingAs($user)->get(route('operations.pos.receipt', $order))
                ->assertOk()
                ->assertSee('POS Receipt');
        } finally {
            DB::rollBack();
        }
    }

    private function setFeatures(bool $pos, bool $cashbox): void
    {
        config()->set('coremarket.features.pos', $pos);
        config()->set('coremarket.features.cashbox_shifts', $cashbox);
        app()->forgetInstance(CoreMarketFeatureAccessService::class);
    }

    private function user(array $permissions): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::query()->firstOrCreate(['name' => 'Web POS UI ' . uniqid(), 'guard_name' => 'web']);

        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }

        $user = new User();
        $user->forceFill([
            'name' => 'Web POS UI User',
            'email' => uniqid('web-pos-ui') . '@example.test',
            'password' => bcrypt('Temporary123!'),
            'user_type' => 'staff',
            'email_verified_at' => now(),
        ])->save();
        $user->assignRole($role);

        return $user;
    }

    private function openShift(User $user): void
    {
        $cashbox = app(CashboxService::class)->createCashbox([
            'name' => 'Web POS UI Cashbox',
            'code' => 'WEB-POS-' . uniqid(),
            'currency' => 'USD',
            'status' => 'active',
        ]);
        app(CashboxService::class)->openShift($cashbox, $user, 0);
    }

    private function productStock(User $user, array $attributes = []): ProductStock
    {
        $now = now();
        $productId = DB::table('products')->insertGetId([
            'name' => $attributes['name'] ?? 'Web POS UI Product ' . uniqid(),
            'user_id' => $user->id,
            'category_id' => 1,
            'unit_price' => $attributes['price'] ?? 20,
            'purchase_price' => $attributes['purchase_price'] ?? 10,
            'current_stock' => $attributes['qty'] ?? 5,
            'barcode' => $attributes['product_barcode'] ?? null,
            'slug' => 'web-pos-ui-' . uniqid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $stockId = DB::table('product_stocks')->insertGetId([
            'product_id' => $productId,
            'variant' => '',
            'sku' => $attributes['sku'] ?? 'WEB-POS-SKU-' . uniqid(),
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

    private function posOrder(): Order
    {
        $id = DB::table('orders')->insertGetId([
            'order_from' => 'pos',
            'shipping_type' => 'pos',
            'payment_type' => 'cash',
            'payment_status' => 'paid',
            'pos_receipt_number' => 'POS-UI-' . uniqid(),
            'pos_request_key' => 'POS-UI-REQ-' . uniqid(),
            'date' => now()->timestamp,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Order::query()->findOrFail($id);
    }
}
