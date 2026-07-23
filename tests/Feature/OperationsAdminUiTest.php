<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\OperationsPermissionSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\InteractsWithCoreMarketTestSchema;
use Tests\TestCase;

class OperationsAdminUiTest extends TestCase
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
        foreach (['inventory_pro', 'purchasing_suppliers', 'returns_management', 'accounting_lite'] as $feature) config()->set("coremarket.features.{$feature}", true);
    }

    public function test_authorized_store_admin_can_view_operations_and_create_supplier(): void
    {
        DB::beginTransaction();
        try {
            $user = $this->user(['operations.view', 'suppliers.view', 'suppliers.create']);
            $this->actingAs($user)->get(route('operations.overview'))->assertOk()->assertSee('Operations Overview');
            $this->actingAs($user)->post(route('operations.suppliers.store'), ['name' => 'Operations QA Supplier', 'is_active' => 1])->assertRedirect();
            $this->assertDatabaseHas('suppliers', ['name' => 'Operations QA Supplier']);
        } finally { DB::rollBack(); }
    }

    public function test_sidebar_hides_operations_without_permission_and_shows_it_when_authorized(): void
    {
        DB::beginTransaction();
        try {
            $this->actingAs($this->user([]));
            $this->assertStringNotContainsString('Operations', view('backend.inc.admin_sidenav')->render());
            $this->actingAs($this->user(['operations.view', 'inventory_movements.view']));
            $html = view('backend.inc.admin_sidenav')->render();
            $this->assertStringContainsString('Operations', $html);
            $this->assertStringContainsString('Movements', $html);
        } finally { DB::rollBack(); }
    }

    public function test_sidebar_lists_only_the_operations_links_a_user_is_allowed_to_see(): void
    {
        DB::beginTransaction();
        try {
            $this->actingAs($this->user(['suppliers.view']));

            $html = view('backend.inc.admin_sidenav')->render();

            $this->assertStringContainsString('Operations', $html);
            $this->assertStringContainsString('Suppliers', $html);
            $this->assertStringNotContainsString('Inventory Movements', $html);
            $this->assertStringNotContainsString('Accounting Summary', $html);
        } finally { DB::rollBack(); }
    }

    public function test_operations_permissions_are_seeded_in_operations_group(): void
    {
        DB::beginTransaction();
        try {
            $this->seed(OperationsPermissionSeeder::class);
            $this->assertDatabaseHas('permissions', ['name' => 'operations.view', 'section' => 'operations']);
            $this->assertDatabaseHas('permissions', ['name' => 'accounting_summary.view', 'section' => 'operations']);
            $this->assertDatabaseHas('permissions', ['name' => 'accounting.core.view', 'section' => 'operations']);
            $this->assertDatabaseHas('permissions', ['name' => 'accounting.tax.audit', 'section' => 'operations']);
            $this->assertDatabaseHas('permissions', ['name' => 'supplier_ledger.view', 'section' => 'operations']);
            $this->assertDatabaseHas('permissions', ['name' => 'supplier_payments.create', 'section' => 'operations']);
            $this->assertDatabaseHas('permissions', ['name' => 'purchase_returns.complete', 'section' => 'operations']);
        } finally { DB::rollBack(); }
    }

    private function user(array $permissions): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::query()->firstOrCreate(['name' => 'Operations Test Role '.uniqid(), 'guard_name' => 'web']);
        foreach ($permissions as $name) $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']));
        $user = new User();
        $user->forceFill(['name' => 'Operations QA', 'email' => uniqid('operations').'@example.test', 'password' => bcrypt('Temporary123!'), 'user_type' => 'staff', 'email_verified_at' => now()])->save();
        $user->assignRole($role);
        return $user;
    }
}
