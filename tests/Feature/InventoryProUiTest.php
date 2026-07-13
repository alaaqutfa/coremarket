<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\InteractsWithCoreMarketTestSchema;
use Tests\TestCase;

class InventoryProUiTest extends TestCase
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
        config()->set('coremarket.features.inventory_pro', true);
    }

    public function test_inventory_dashboard_and_stock_view_render_for_authorized_user(): void
    {
        DB::beginTransaction();
        try {
            [$productId, $stockId] = $this->inventoryProduct('VAR-LOOKUP');
            $user = $this->user(['inventory.dashboard.view', 'inventory.stock.view', 'inventory.stock.audit']);
            $this->actingAs($user)->get(route('operations.inventory.dashboard'))->assertOk()->assertSee('Inventory Dashboard');
            $this->actingAs($user)->get(route('operations.inventory.stock'))->assertOk()->assertSee('VAR-LOOKUP')->assertSee('Variant QA');
            $this->actingAs($user)->get(route('operations.inventory.audit'))->assertOk()->assertSee('Stock Audit')->assertSee('Current stock mismatches');
        } finally { DB::rollBack(); }
    }

    public function test_barcode_lookup_and_low_stock_report_use_variant_identity(): void
    {
        DB::beginTransaction();
        try {
            [, $stockId] = $this->inventoryProduct('LOW-VARIANT', 2);
            $user = $this->user(['inventory.barcode_lookup.view', 'inventory.low_stock.view']);
            $this->actingAs($user)->get(route('operations.inventory.barcode-lookup', ['barcode_or_sku' => 'LOW-VARIANT']))->assertOk()->assertSee('variant_barcode')->assertSee('Inventory QA Product');
            $this->actingAs($user)->get(route('operations.inventory.low-stock'))->assertOk()->assertSee('LOW-VARIANT');
        } finally { DB::rollBack(); }
    }

    public function test_stock_adjustment_updates_qty_logs_movement_and_prevents_negative_stock(): void
    {
        DB::beginTransaction();
        try {
            [$productId, $stockId] = $this->inventoryProduct('ADJUST-VARIANT', 3);
            $user = $this->user(['inventory.stock.adjust']);
            $this->actingAs($user)->post(route('operations.inventory.stock.adjust.store', $stockId), ['adjustment_type' => 'increase', 'quantity' => 2, 'reason' => 'Count correction'])->assertRedirect(route('operations.inventory.stock'));
            $this->assertSame(5, (int) DB::table('product_stocks')->where('id', $stockId)->value('qty'));
            $this->assertDatabaseHas('inventory_movements', ['product_stock_id' => $stockId, 'movement_type' => 'adjustment', 'direction' => 'in', 'quantity' => 2]);
            $this->actingAs($user)->post(route('operations.inventory.stock.adjust.store', $stockId), ['adjustment_type' => 'decrease', 'quantity' => 1, 'reason' => 'Count correction'])->assertRedirect();
            $this->assertSame(4, (int) DB::table('product_stocks')->where('id', $stockId)->value('qty'));
            $this->assertDatabaseHas('inventory_movements', ['product_stock_id' => $stockId, 'movement_type' => 'adjustment', 'direction' => 'out', 'quantity' => 1]);
            $this->actingAs($user)->post(route('operations.inventory.stock.adjust.store', $stockId), ['adjustment_type' => 'decrease', 'quantity' => 6, 'reason' => 'Invalid'])->assertSessionHasErrors();
        } finally { DB::rollBack(); }
    }

    public function test_inventory_pages_are_hidden_when_runtime_feature_is_disabled(): void
    {
        DB::beginTransaction();
        try {
            config()->set('coremarket.features.inventory_pro', false);
            $user = $this->user(['inventory.dashboard.view']);
            $this->actingAs($user)->get(route('operations.inventory.dashboard'))->assertNotFound();
        } finally { DB::rollBack(); }
    }

    private function inventoryProduct(string $barcode, int $qty = 5): array
    {
        $now = now();
        $productId = DB::table('products')->insertGetId(['name' => 'Inventory QA Product', 'user_id' => 1, 'category_id' => 1, 'unit_price' => 20, 'purchase_price' => 8, 'current_stock' => $qty, 'low_stock_quantity' => 5, 'barcode' => 'PROD-'.uniqid(), 'slug' => 'inventory-'.uniqid(), 'created_at' => $now, 'updated_at' => $now]);
        $stockId = DB::table('product_stocks')->insertGetId(['product_id' => $productId, 'variant' => 'Variant QA', 'sku' => 'SKU-'.uniqid(), 'barcode' => $barcode, 'price' => 20, 'qty' => $qty, 'created_at' => $now, 'updated_at' => $now]);
        return [$productId, $stockId];
    }

    private function user(array $permissions): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::query()->firstOrCreate(['name' => 'Inventory Pro Test '.uniqid(), 'guard_name' => 'web']);
        foreach ($permissions as $permission) $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        $user = new User();
        $user->forceFill(['name' => 'Inventory Pro QA', 'email' => uniqid('inventory').'@example.test', 'password' => bcrypt('Temporary123!'), 'user_type' => 'staff', 'email_verified_at' => now()])->save();
        $user->assignRole($role);
        return $user;
    }
}
