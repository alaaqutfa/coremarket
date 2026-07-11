<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Support\InteractsWithCoreMarketTestSchema;

class AdminAddonRequestCatalogTest extends TestCase
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
    }
    public function test_store_admin_can_access_addon_request_catalog(): void
    {
        DB::beginTransaction();

        try {
            config()->set('coremarket.runtime.applied_plan_code', 'starter');
            config()->set('coremarket.runtime.store_mode', 'single_store');

            $user = $this->makeUserWithRole(
                'Store Admin Addon QA',
                'storeadmin.addons@example.test',
                'staff',
                config('coremarket.access.store_admin_role', 'store_admin')
            );

            $this->actingAs($user)
                ->get(route('addons.index'))
                ->assertOk()
                ->assertSee('Addon Requests')
                ->assertSee('CorePilotOS owns pricing')
                ->assertSee('View My Subscription')
                ->assertSee('Blog / Content Pages')
                ->assertDontSee('Install/Update Addon')
                ->assertDontSee('Purchase code')
                ->assertDontSee('Status updated successfully');
        } finally {
            DB::rollBack();
        }
    }

    public function test_owner_admin_sees_internal_managed_baseline_note(): void
    {
        DB::beginTransaction();

        try {
            config()->set('coremarket.runtime.applied_plan_code', 'business');
            config()->set('coremarket.runtime.store_mode', 'single_store');

            $user = $this->makeUserWithRole(
                'Owner Addon QA',
                'owner.addons@example.test',
                'admin',
                'Addon Owner'
            );

            $this->actingAs($user)
                ->get(route('addons.index'))
                ->assertOk()
                ->assertSee('CorePilotOS owns pricing');
        } finally {
            DB::rollBack();
        }
    }

    public function test_legacy_addon_install_route_is_neutralized(): void
    {
        DB::beginTransaction();

        try {
            $user = $this->makeUserWithRole(
                'Owner Legacy Addon QA',
                'owner.addons.legacy@example.test',
                'admin',
                'Addon Legacy Owner'
            );

            $this->actingAs($user)
                ->get(route('addons.create'))
                ->assertNotFound();
        } finally {
            DB::rollBack();
        }
    }

    public function test_legacy_addon_activation_route_is_neutralized(): void
    {
        DB::beginTransaction();

        try {
            $user = $this->makeUserWithRole(
                'Owner Legacy Activation QA',
                'owner.addons.activation@example.test',
                'admin',
                'Addon Activation Owner'
            );

            $this->actingAs($user)
                ->post(route('addons.activation'), ['id' => 1, 'status' => 1])
                ->assertNotFound();
        } finally {
            DB::rollBack();
        }
    }

    private function makeUserWithRole(string $name, string $email, string $userType, string $roleName): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::query()->firstOrCreate([
            'name' => $roleName,
            'guard_name' => 'web',
        ]);

        $user = new User();
        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'password' => bcrypt('Temporary123!'),
            'user_type' => $userType,
            'email_verified_at' => now(),
        ])->save();

        $user->assignRole($role);

        return $user;
    }
}
