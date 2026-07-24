<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\InteractsWithCoreMarketTestSchema;
use Tests\TestCase;

class OperationsNavigationUxTest extends TestCase
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
        BusinessSetting::query()->whereIn('type', ['coremarket_runtime_features', 'coremarket_runtime_limits'])->delete();
        Cache::forget('business_settings');
        foreach ([
            'inventory_pro',
            'purchasing_suppliers',
            'returns_management',
            'accounting_lite',
            'accounting_core',
            'cashbox_shifts',
            'pos',
        ] as $feature) {
            config()->set("coremarket.features.{$feature}", true);
        }
    }

    public function test_admin_sees_grouped_sidebar_and_all_safe_quick_actions(): void
    {
        DB::beginTransaction();
        try {
            $user = $this->user('admin', ['add_new_product']);
            $this->actingAs($user);

            $sidebar = view('backend.inc.admin_sidenav')->render();
            $sales = strpos($sidebar, 'data-coremarket-nav-group="sales"');
            $inventory = strpos($sidebar, 'data-coremarket-nav-group="inventory"');
            $purchasing = strpos($sidebar, 'data-coremarket-nav-group="purchasing"');
            $pricing = strpos($sidebar, 'data-coremarket-nav-group="pricing"');
            $accounting = strpos($sidebar, 'data-coremarket-nav-group="accounting"');

            $this->assertIsInt($sales);
            $this->assertTrue($sales < $inventory && $inventory < $purchasing && $purchasing < $pricing && $pricing < $accounting);
            $this->assertTrue($inventory < strpos($sidebar, 'Product Families') && strpos($sidebar, 'Product Families') < $purchasing);
            $this->assertTrue($sales < strpos($sidebar, 'Sales Returns') && strpos($sidebar, 'Sales Returns') < $inventory);
            $this->assertTrue($purchasing < strpos($sidebar, 'Purchase Returns') && strpos($sidebar, 'Purchase Returns') < $pricing);
            $this->assertTrue($pricing < strpos($sidebar, 'Price Lists') && strpos($sidebar, 'Price Lists') < $accounting);
            $this->assertTrue($accounting < strpos($sidebar, 'Accounting Reports'));

            $response = $this->get(route('operations.overview'))->assertOk()->assertSee('Quick Actions');
            foreach ([
                'POS Sale',
                'Purchase Stock',
                'Receive Purchase',
                'Return to Supplier',
                'Supplier Payment',
                'Supplier Statement',
                'Add Product',
                'Manage Stock',
                'Price Lists',
                'Inventory Policy',
                'Accounting Reports',
            ] as $label) {
                $response->assertSee($label);
            }
            foreach ([
                'operations.pos',
                'operations.purchase-orders.create',
                'operations.purchase-orders',
                'operations.purchase-returns.create',
                'operations.suppliers',
                'products.create',
                'operations.inventory.stock',
                'operations.price-lists.index',
                'operations.inventory.policy',
                'operations.accounting.reports',
            ] as $routeName) {
                $response->assertSee(route($routeName), false);
            }
        } finally {
            DB::rollBack();
            Cache::forget('business_settings');
        }
    }

    public function test_staff_sees_only_permitted_navigation_and_quick_actions(): void
    {
        DB::beginTransaction();
        try {
            $user = $this->user('staff', ['operations.view', 'inventory.stock.view']);
            $this->actingAs($user);

            $response = $this->get(route('operations.overview'))
                ->assertOk()
                ->assertSee('Manage Stock')
                ->assertDontSee('Purchase Stock')
                ->assertDontSee('Supplier Payment')
                ->assertDontSee('Accounting Reports');

            $sidebar = view('backend.inc.admin_sidenav')->render();
            $this->assertStringContainsString('data-coremarket-nav-group="inventory"', $sidebar);
            $this->assertStringNotContainsString('data-coremarket-nav-group="purchasing"', $sidebar);
            $this->assertStringNotContainsString('data-coremarket-nav-group="pricing"', $sidebar);
            $this->assertStringNotContainsString('data-coremarket-nav-group="accounting"', $sidebar);
            $response->assertSee(route('operations.inventory.stock'), false);
        } finally {
            DB::rollBack();
            Cache::forget('business_settings');
        }
    }

    public function test_shortcuts_are_hidden_when_destination_view_permission_is_missing(): void
    {
        DB::beginTransaction();
        try {
            $user = $this->user('staff', [
                'operations.view',
                'purchase_orders.receive',
                'supplier_payments.create',
                'supplier_ledger.view',
            ]);

            $this->actingAs($user)
                ->get(route('operations.overview'))
                ->assertOk()
                ->assertDontSee('Receive Purchase')
                ->assertDontSee('Supplier Payment')
                ->assertDontSee('Supplier Statement');
        } finally {
            DB::rollBack();
            Cache::forget('business_settings');
        }
    }

    private function user(string $userType, array $permissions): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::query()->firstOrCreate([
            'name' => 'Operations Navigation '.uniqid(),
            'guard_name' => 'web',
        ]);
        foreach ($permissions as $name) {
            $role->givePermissionTo(Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]));
        }

        $user = new User();
        $user->forceFill([
            'name' => 'Operations Navigation QA',
            'email' => uniqid('operations-navigation').'@example.test',
            'password' => bcrypt('Temporary123!'),
            'user_type' => $userType,
            'email_verified_at' => now(),
        ])->save();
        $user->assignRole($role);

        return $user;
    }
}
