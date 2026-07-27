<?php

namespace Tests\Feature;

use Database\Seeders\StaffRolePresetSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\InteractsWithCoreMarketTestSchema;
use Tests\TestCase;

class StaffRoleAccessMatrixTest extends TestCase
{
    use InteractsWithCoreMarketTestSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensurePermissionTables();
        foreach (['pos', 'cashbox_shifts', 'inventory_pro', 'purchasing_suppliers', 'returns_management', 'accounting_lite', 'accounting_core'] as $feature) {
            config()->set("coremarket.features.{$feature}", true);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_staff_role_presets_are_idempotent_and_use_existing_permissions(): void
    {
        DB::beginTransaction();

        try {
            $this->seed(StaffRolePresetSeeder::class);
            $this->seed(StaffRolePresetSeeder::class);

            foreach (config('coremarket.access.staff_role_presets') as $roleName => $permissions) {
                $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->firstOrFail();

                $this->assertSame(1, Role::query()->where('name', $roleName)->where('guard_name', 'web')->count());
                $this->assertEqualsCanonicalizing(array_values(array_unique($permissions)), $role->permissions->pluck('name')->all());
            }
        } finally {
            DB::rollBack();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function test_cashier_accountant_and_operations_roles_have_bounded_access(): void
    {
        DB::beginTransaction();

        try {
            $this->seed(StaffRolePresetSeeder::class);

            $cashier = Role::findByName('cashier');
            $accountant = Role::findByName('accountant');
            $dataEntry = Role::findByName('data_entry_purchasing');
            $warehouse = Role::findByName('warehouse_keeper');
            $marketing = Role::findByName('marketing_employee');
            $designer = Role::findByName('designer_content');
            $owner = Role::findByName('owner_general_manager');

            $this->assertTrue($cashier->hasPermissionTo('pos.view'));
            $this->assertTrue($cashier->hasPermissionTo('pos.sell'));
            $this->assertFalse($cashier->hasPermissionTo('supplier_payments.create'));
            $this->assertFalse($cashier->hasPermissionTo('accounting_summary.view'));

            $this->assertTrue($accountant->hasPermissionTo('accounting_summary.view'));
            $this->assertTrue($accountant->hasPermissionTo('supplier_ledger.view'));
            $this->assertTrue($accountant->hasPermissionTo('supplier_payments.create'));
            $this->assertFalse($accountant->hasPermissionTo('inventory.families.manage'));

            $this->assertTrue($dataEntry->hasPermissionTo('purchase_orders.create'));
            $this->assertTrue($dataEntry->hasPermissionTo('purchase_orders.receive'));
            $this->assertTrue($warehouse->hasPermissionTo('inventory_movements.view'));
            $this->assertTrue($warehouse->hasPermissionTo('purchase_returns.complete'));

            $this->assertFalse($marketing->hasPermissionTo('accounting_summary.view'));
            $this->assertFalse($designer->hasPermissionTo('accounting_summary.view'));
            $this->assertTrue($owner->hasPermissionTo('operations.view'));
            $this->assertTrue($owner->hasPermissionTo('view_all_staffs'));
        } finally {
            DB::rollBack();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function test_sidebar_visibility_follows_staff_role_permissions(): void
    {
        DB::beginTransaction();

        try {
            $this->seed(StaffRolePresetSeeder::class);

            $cashierHtml = $this->renderSidebarFor('cashier', 'cashier.matrix@example.test');
            $this->assertStringContainsString('POS', $cashierHtml);
            $this->assertStringNotContainsString('Supplier Payments', $cashierHtml);
            $this->assertStringNotContainsString('Accounting Reports', $cashierHtml);

            $accountantHtml = $this->renderSidebarFor('accountant', 'accountant.matrix@example.test');
            $this->assertStringContainsString('Accounting Reports', $accountantHtml);
            $this->assertStringContainsString('Suppliers', $accountantHtml);
            $this->assertStringNotContainsString('Product Families', $accountantHtml);

            $dataEntryHtml = $this->renderSidebarFor('data_entry_purchasing', 'data.matrix@example.test');
            $this->assertStringContainsString('Purchase Orders', $dataEntryHtml);

            $warehouseHtml = $this->renderSidebarFor('warehouse_keeper', 'warehouse.matrix@example.test');
            $this->assertStringContainsString('Stock Movements', $warehouseHtml);
            $this->assertStringContainsString('Purchase Returns', $warehouseHtml);

            $marketingHtml = $this->renderSidebarFor('marketing_employee', 'marketing.matrix@example.test');
            $this->assertStringNotContainsString('Accounting Reports', $marketingHtml);
        } finally {
            DB::rollBack();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function renderSidebarFor(string $roleName, string $email): string
    {
        $user = \App\Models\User::query()->create([
            'name' => $roleName,
            'email' => $email,
            'password' => bcrypt('Temporary123!'),
            'user_type' => 'staff',
            'email_verified_at' => now(),
        ]);
        $user->assignRole($roleName);
        $this->actingAs($user);

        return view('backend.inc.admin_sidenav')->render();
    }
}
