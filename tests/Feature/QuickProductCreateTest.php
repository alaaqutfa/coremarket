<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CoreMarketProductPricingService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\InteractsWithCoreMarketTestSchema;
use Tests\TestCase;

class QuickProductCreateTest extends TestCase
{
    use InteractsWithCoreMarketTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('coremarket.runtime_snapshot.connection', 'mysql');
        config()->set('coremarket.features.purchasing_suppliers', true);
        $this->ensureBusinessSettingsTable();
        $this->ensurePermissionTables();
        $this->ensureLegacyUserColumns();
        $this->ensureAdminSupportTables();
    }

    public function test_authorized_data_entry_can_create_simple_product_and_purchase_row_payload(): void
    {
        DB::beginTransaction();
        try {
            $user = $this->user(['purchase_orders.create', 'add_new_product']);
            $identity = 'QUICK-'.uniqid();

            $response = $this->actingAs($user)->postJson(route('operations.purchase-orders.quick-products.store'), [
                'name' => 'Quick Product',
                'sku' => $identity,
                'barcode' => 'BAR-'.$identity,
                'unit' => 'pc',
                'cost_price' => 10,
                'margin_percent' => 50,
                'sale_price' => 14,
                'tax_enabled' => true,
                'tax_rate' => 11,
                'opening_stock' => 0,
            ]);

            $response->assertCreated()
                ->assertJsonPath('ok', true)
                ->assertJsonPath('data.regular_price', 15)
                ->assertJsonPath('data.sale_price', 14)
                ->assertJsonPath('data.margin_percent', 50)
                ->assertJsonPath('data.tax_enabled', true);

            $productId = $response->json('data.product_id');
            $stockId = $response->json('data.product_stock_id');
            $this->assertDatabaseHas('products', [
                'id' => $productId,
                'purchase_price' => 10,
                'wholesale_price' => 10,
                'unit_price' => 15,
                'discount' => 1,
                'discount_type' => 'amount',
            ]);
            $this->assertDatabaseHas('product_stocks', [
                'id' => $stockId,
                'product_id' => $productId,
                'sku' => $identity,
                'barcode' => 'BAR-'.$identity,
                'price' => 15,
                'qty' => 0,
            ]);
        } finally {
            DB::rollBack();
        }
    }

    public function test_cashier_without_product_create_permission_is_forbidden(): void
    {
        DB::beginTransaction();
        try {
            $user = $this->user(['purchase_orders.create']);
            $this->actingAs($user)->postJson(route('operations.purchase-orders.quick-products.store'), [
                'name' => 'Forbidden Product',
                'cost_price' => 1,
                'regular_price' => 2,
            ])->assertForbidden();
        } finally {
            DB::rollBack();
        }
    }

    public function test_product_creation_blocks_opening_stock_without_creating_product(): void
    {
        DB::beginTransaction();
        try {
            DB::table('business_settings')->updateOrInsert(
                ['type' => 'inventory.strict_inventory_mode'],
                ['value' => '1', 'updated_at' => now(), 'created_at' => now()]
            );
            $user = $this->user(['purchase_orders.create', 'add_new_product']);

            $this->actingAs($user)->postJson(route('operations.purchase-orders.quick-products.store'), [
                'name' => 'Strict Product',
                'sku' => 'STRICT-'.uniqid(),
                'cost_price' => 4,
                'regular_price' => 8,
                'opening_stock' => 2,
            ])->assertUnprocessable()
                ->assertJsonPath('errors.opening_stock.0', 'Create the product first, then use an Opening Stock document.');

            $this->assertDatabaseMissing('products', ['name' => 'Strict Product']);
        } finally {
            DB::rollBack();
        }
    }

    public function test_validation_errors_are_json_and_duplicate_identity_is_not_created(): void
    {
        DB::beginTransaction();
        try {
            $user = $this->user(['purchase_orders.create', 'add_new_product']);
            $identity = DB::table('product_stocks')->whereNotNull('sku')->value('sku');

            $this->actingAs($user)->postJson(route('operations.purchase-orders.quick-products.store'), [
                'name' => 'Duplicate Identity',
                'sku' => $identity,
                'cost_price' => 5,
                'regular_price' => 8,
            ])->assertUnprocessable()
                ->assertJsonStructure(['ok', 'message', 'errors' => ['sku']]);

            $this->actingAs($user)->postJson(route('operations.purchase-orders.quick-products.store'), [])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['name', 'cost_price', 'regular_price', 'margin_percent']);
        } finally {
            DB::rollBack();
        }
    }

    public function test_product_pricing_keeps_sale_price_separate_and_calculates_both_directions(): void
    {
        $pricing = app(CoreMarketProductPricingService::class);

        $fromMargin = $pricing->normalize([
            'cost_price' => 20,
            'margin_percent' => 25,
            'sale_price' => 23,
        ]);
        $this->assertSame(25.0, $fromMargin['regular_price']);
        $this->assertSame(23.0, $fromMargin['sale_price']);

        $fromRegular = $pricing->normalize([
            'cost_price' => 20,
            'regular_price' => 30,
        ]);
        $this->assertSame(50.0, $fromRegular['margin_percent']);
        $this->assertNull($fromRegular['sale_price']);
    }

    public function test_purchase_form_contains_ajax_modal_and_manual_add_item_remains_available(): void
    {
        DB::beginTransaction();
        try {
            $user = $this->user(['purchase_orders.create', 'add_new_product']);
            $this->actingAs($user)->get(route('operations.purchase-orders.create'))
                ->assertOk()
                ->assertSee('id="purchase-product-not-found-modal"', false)
                ->assertSee('id="purchase-quick-product-modal"', false)
                ->assertSee('id="add-purchase-item"', false)
                ->assertSee('quickCreateUrl', false)
                ->assertSee('registerQuickProduct', false);
        } finally {
            DB::rollBack();
        }
    }

    private function user(array $permissions): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::query()->firstOrCreate(['name' => 'Quick Product '.uniqid(), 'guard_name' => 'web']);
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }
        $user = new User();
        $user->forceFill([
            'name' => 'Quick Product User',
            'email' => uniqid('quick-product').'@example.test',
            'password' => bcrypt('Temporary123!'),
            'user_type' => 'staff',
            'email_verified_at' => now(),
        ])->save();
        $user->assignRole($role);

        return $user;
    }
}
