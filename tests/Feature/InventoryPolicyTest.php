<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\ProductStock;
use App\Models\User;
use App\Services\CoreMarketInventoryPolicyService;
use App\Services\InventoryProService;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\InteractsWithCoreMarketTestSchema;
use Tests\TestCase;

class InventoryPolicyTest extends TestCase
{
    use InteractsWithCoreMarketTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('coremarket.runtime_snapshot.connection', 'mysql');
        config()->set('coremarket.features.inventory_pro', true);
        config()->set('coremarket.inventory.strict_inventory_mode', false);
        config()->set('coremarket.inventory.allow_negative_stock', false);
        $this->ensureBusinessSettingsTable();
        $this->ensurePermissionTables();
        $this->ensureLegacyUserColumns();
        $this->ensureAdminSupportTables();
    }

    public function test_defaults_and_policy_snapshot_are_safe(): void
    {
        DB::beginTransaction();
        try {
            $this->clearPolicySettings();
            $snapshot = app(CoreMarketInventoryPolicyService::class)->policySnapshot();

            $this->assertFalse($snapshot['strict_inventory_mode']);
            $this->assertFalse($snapshot['allow_negative_stock']);
            $this->assertTrue($snapshot['can_create_opening_stock']);
            $this->assertTrue($snapshot['can_adjust_stock_manually']);
        } finally {
            DB::rollBack();
            Cache::forget('business_settings');
        }
    }

    public function test_negative_stock_is_blocked_by_default_and_allowed_when_enabled(): void
    {
        DB::beginTransaction();
        try {
            $this->clearPolicySettings();
            [, $stockId] = $this->inventoryProduct(1);
            $stock = ProductStock::findOrFail($stockId);
            $policy = app(CoreMarketInventoryPolicyService::class);

            try {
                $policy->assertCanDecreaseStock($stock, 2, 'test sale');
                $this->fail('Negative stock should be blocked by default.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString('Insufficient stock', $exception->getMessage());
            }

            $this->setting(CoreMarketInventoryPolicyService::NEGATIVE_STOCK_SETTING, '1');
            $policy->assertCanDecreaseStock($stock, 2, 'test sale');
            app(InventoryProService::class)->adjustStock($stock, [
                'adjustment_type' => 'decrease',
                'quantity' => 2,
                'reason' => 'Policy test',
            ]);

            $this->assertSame(-1.0, (float) $stock->fresh()->qty);
        } finally {
            DB::rollBack();
            Cache::forget('business_settings');
        }
    }

    public function test_strict_mode_removes_opening_stock_input(): void
    {
        DB::beginTransaction();
        try {
            $this->clearPolicySettings();
            $this->setting(CoreMarketInventoryPolicyService::STRICT_MODE_SETTING, '1');
            $normalized = app(CoreMarketInventoryPolicyService::class)->validateProductStockInput([
                'current_stock' => 12,
                'qty_Red' => 4,
                'unit_price' => 20,
            ]);

            $this->assertSame(0, $normalized['current_stock']);
            $this->assertSame(0, $normalized['qty_Red']);
            $this->assertSame(20, $normalized['unit_price']);
        } finally {
            DB::rollBack();
            Cache::forget('business_settings');
        }
    }

    public function test_authorized_user_can_update_inventory_policy(): void
    {
        DB::beginTransaction();
        try {
            $this->clearPolicySettings();
            $user = $this->user(['inventory.stock.adjust']);

            $this->actingAs($user)
                ->get(route('operations.inventory.policy'))
                ->assertOk()
                ->assertSee('Inventory Policy');

            $this->actingAs($user)->post(route('operations.inventory.policy.update'), [
                'strict_inventory_mode' => 1,
                'allow_negative_stock' => 0,
            ])->assertRedirect();

            Cache::forget('business_settings');
            $this->assertTrue(app(CoreMarketInventoryPolicyService::class)->strictInventoryMode());
            $this->assertFalse(app(CoreMarketInventoryPolicyService::class)->allowNegativeStock());
        } finally {
            DB::rollBack();
            Cache::forget('business_settings');
        }
    }

    private function clearPolicySettings(): void
    {
        BusinessSetting::query()->whereIn('type', [
            CoreMarketInventoryPolicyService::STRICT_MODE_SETTING,
            CoreMarketInventoryPolicyService::NEGATIVE_STOCK_SETTING,
        ])->delete();
        Cache::forget('business_settings');
    }

    private function setting(string $type, string $value): void
    {
        $setting = BusinessSetting::query()->where('type', $type)->whereNull('lang')->first() ?: new BusinessSetting();
        $setting->forceFill(['type' => $type, 'value' => $value, 'lang' => null])->save();
        Cache::forget('business_settings');
    }

    private function inventoryProduct(int $qty): array
    {
        $now = now();
        $productId = DB::table('products')->insertGetId([
            'name' => 'Inventory Policy Product',
            'user_id' => 1,
            'category_id' => 1,
            'unit_price' => 20,
            'purchase_price' => 8,
            'current_stock' => $qty,
            'slug' => 'inventory-policy-'.uniqid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $stockId = DB::table('product_stocks')->insertGetId([
            'product_id' => $productId,
            'variant' => '',
            'sku' => 'POLICY-'.uniqid(),
            'price' => 20,
            'qty' => $qty,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$productId, $stockId];
    }

    private function user(array $permissions): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::query()->firstOrCreate(['name' => 'Inventory Policy Test '.uniqid(), 'guard_name' => 'web']);
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }
        $user = new User();
        $user->forceFill([
            'name' => 'Inventory Policy QA',
            'email' => uniqid('inventory-policy').'@example.test',
            'password' => bcrypt('Temporary123!'),
            'user_type' => 'staff',
            'email_verified_at' => now(),
        ])->save();
        $user->assignRole($role);

        return $user;
    }
}
