<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseReceivingService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\InteractsWithCoreMarketTestSchema;
use Tests\TestCase;

class PurchasingUiTest extends TestCase
{
    use InteractsWithCoreMarketTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('coremarket.runtime_snapshot.connection', 'mysql');
        $this->ensureBusinessSettingsTable();
        $this->ensurePermissionTables();
        $this->ensureLegacyUserColumns();
        $this->ensureAdminSupportTables();
        config()->set('coremarket.features.purchasing_suppliers', true);
    }

    public function test_authorized_user_can_filter_suppliers_and_create_purchase_order_from_ui(): void
    {
        DB::beginTransaction();
        try {
            [$supplier, $productId, $stockId] = $this->fixtures();
            $user = $this->user(['suppliers.view', 'purchase_orders.view', 'purchase_orders.create']);

            $this->actingAs($user)->get(route('operations.suppliers', ['search' => $supplier->name]))
                ->assertOk()->assertSee($supplier->name);
            $this->actingAs($user)->get(route('operations.purchase-orders.create'))
                ->assertOk()
                ->assertSee('id="add-purchase-item"', false)
                ->assertSee("document.getElementById('add-purchase-item').addEventListener", false)
                ->assertSee('function addRow()', false);

            $response = $this->actingAs($user)->post(route('operations.purchase-orders.store'), [
                'supplier_id' => $supplier->id,
                'ordered_at' => now()->toDateString(),
                'currency' => 'USD',
                'items' => [[
                    'product_id' => $productId,
                    'product_stock_id' => $stockId,
                    'quantity_ordered' => 4,
                    'unit_cost' => 7.5,
                    'tax_amount' => 0,
                    'discount_amount' => 0,
                ]],
            ]);

            $response->assertRedirect();
            $this->assertDatabaseHas('purchase_order_items', ['product_id' => $productId, 'product_stock_id' => $stockId, 'quantity_ordered' => 4]);
        } finally { DB::rollBack(); }
    }

    public function test_partial_receiving_from_ui_writes_one_movement_and_receipt_pages_render(): void
    {
        DB::beginTransaction();
        try {
            [$supplier, $productId, $stockId] = $this->fixtures();
            $order = app(PurchaseReceivingService::class)->createPurchaseOrder(['supplier_id' => $supplier->id, 'status' => 'ordered'], [[
                'product_id' => $productId, 'product_stock_id' => $stockId, 'quantity_ordered' => 5, 'unit_cost' => 8,
            ]]);
            $item = $order->items->first();
            $user = $this->user(['purchase_orders.view', 'purchase_orders.receive']);
            $payload = ['receipt_key' => 'ui-receipt-'.uniqid(), 'items' => [[
                'purchase_order_item_id' => $item->id, 'quantity_received' => 2, 'unit_cost' => 8,
            ]]];

            $this->actingAs($user)->post(route('operations.purchase-orders.receive', $order), $payload)->assertSessionHas('success');
            $this->actingAs($user)->post(route('operations.purchase-orders.receive', $order), $payload)->assertSessionHas('success');
            $this->assertSame('partially_received', $order->fresh()->status);
            $this->assertDatabaseCount('inventory_movements', 1);
            $receiptId = DB::table('purchase_receipts')->where('receipt_key', $payload['receipt_key'])->value('id');
            $this->actingAs($user)->get(route('operations.purchase-orders.show', $order))->assertOk()->assertSee('Receipts history');
            $this->actingAs($user)->get(route('operations.purchase-receipts'))->assertOk()->assertSee($payload['receipt_key']);
            $this->actingAs($user)->get(route('operations.purchase-receipts.show', $receiptId))->assertOk()->assertSee($payload['receipt_key']);
        } finally { DB::rollBack(); }
    }

    public function test_purchasing_pages_are_hidden_when_feature_is_disabled(): void
    {
        DB::beginTransaction();
        try {
            config()->set('coremarket.features.purchasing_suppliers', false);
            $this->actingAs($this->user(['purchase_orders.view']))->get(route('operations.purchase-orders'))->assertNotFound();
        } finally { DB::rollBack(); }
    }

    public function test_purchasing_sidebar_shows_only_authorized_purchasing_links(): void
    {
        DB::beginTransaction();
        try {
            $this->actingAs($this->user(['purchase_orders.view']));
            $html = view('backend.inc.admin_sidenav')->render();

            $this->assertStringContainsString('Purchasing', $html);
            $this->assertStringContainsString('Purchase Orders', $html);
            $this->assertStringContainsString('Purchase Receipts', $html);
            $this->assertStringNotContainsString('operations/suppliers', $html);
        } finally { DB::rollBack(); }
    }

    private function fixtures(): array
    {
        $now = now();
        $supplier = Supplier::query()->create(['name' => 'Purchasing UI '.uniqid(), 'is_active' => true]);
        $productId = DB::table('products')->insertGetId(['name' => 'Purchasing UI Product', 'user_id' => 1, 'category_id' => 1, 'unit_price' => 20, 'purchase_price' => 5, 'current_stock' => 2, 'slug' => 'purchasing-ui-'.uniqid(), 'created_at' => $now, 'updated_at' => $now]);
        $stockId = DB::table('product_stocks')->insertGetId(['product_id' => $productId, 'variant' => 'Purchasing Variant', 'sku' => 'PUR-'.uniqid(), 'price' => 20, 'qty' => 2, 'created_at' => $now, 'updated_at' => $now]);
        return [$supplier, $productId, $stockId];
    }

    private function user(array $permissions): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::query()->firstOrCreate(['name' => 'Purchasing UI '.uniqid(), 'guard_name' => 'web']);
        foreach ($permissions as $permission) $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        $user = new User();
        $user->forceFill(['name' => 'Purchasing QA', 'email' => uniqid('purchasing').'@example.test', 'password' => bcrypt('Temporary123!'), 'user_type' => 'staff', 'email_verified_at' => now()])->save();
        $user->assignRole($role);
        return $user;
    }
}
