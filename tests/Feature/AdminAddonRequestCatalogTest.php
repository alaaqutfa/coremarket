<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CorePilotAddonRequestClient;
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

    public function test_store_admin_can_submit_a_safe_synced_addon_request(): void
    {
        DB::beginTransaction();

        try {
            $this->persistSyncedCatalog([
                ['code' => 'pos_module', 'name' => 'POS Module', 'status' => 'available', 'setup_available' => true],
            ]);

            $user = $this->makeUserWithRole(
                'Store Admin Request QA',
                'storeadmin.request@example.test',
                'staff',
                config('coremarket.access.store_admin_role', 'store_admin')
            );

            $this->mock(CorePilotAddonRequestClient::class, function ($mock): void {
                $mock->shouldReceive('configured')->once()->andReturnTrue();
                $mock->shouldReceive('submit')->once()->with(\Mockery::on(function (array $payload): bool {
                    return $payload['addon_code'] === 'pos_module'
                        && $payload['setup_requested'] === true
                        && $payload['note'] === 'Please include onboarding.'
                        && $payload['catalog_version'] === '2026-07-12'
                        && $payload['requested_by']['email'] === 'storeadmin.request@example.test';
                }))->andReturn(['status' => 'pending']);
            });

            $this->actingAs($user)
                ->from(route('addons.index'))
                ->post(route('addons.request'), [
                    'addon_code' => 'pos_module',
                    'setup_requested' => true,
                    'note' => 'Please include onboarding.',
                ])
                ->assertRedirect(route('addons.index'))
                ->assertSessionHas('success', 'Request submitted for review.');
        } finally {
            DB::rollBack();
        }
    }

    public function test_store_admin_cannot_use_legacy_technical_addon_post_route(): void
    {
        DB::beginTransaction();

        try {
            $user = $this->makeUserWithRole(
                'Store Admin Legacy Addon QA',
                'storeadmin.legacy-addon@example.test',
                'staff',
                config('coremarket.access.store_admin_role', 'store_admin')
            );

            $this->actingAs($user)
                ->post(route('addons.store'), ['addon_code' => 'pos_module'])
                ->assertForbidden();
        } finally {
            DB::rollBack();
        }
    }

    public function test_active_or_unknown_addons_cannot_be_requested(): void
    {
        DB::beginTransaction();

        try {
            $this->persistSyncedCatalog([
                ['code' => 'pos_module', 'name' => 'POS Module', 'status' => 'active'],
            ]);

            $user = $this->makeUserWithRole(
                'Store Admin Ineligible Addon QA',
                'storeadmin.ineligible-addon@example.test',
                'staff',
                config('coremarket.access.store_admin_role', 'store_admin')
            );

            foreach (['pos_module', 'unknown_addon'] as $addonCode) {
                $this->actingAs($user)
                    ->from(route('addons.index'))
                    ->post(route('addons.request'), ['addon_code' => $addonCode])
                    ->assertRedirect(route('addons.index'))
                    ->assertSessionHasErrors('addon_request');
            }
        } finally {
            DB::rollBack();
        }
    }

    public function test_request_channel_failure_returns_a_safe_support_message(): void
    {
        DB::beginTransaction();

        try {
            $this->persistSyncedCatalog([
                ['code' => 'pos_module', 'name' => 'POS Module', 'status' => 'available'],
            ]);

            $user = $this->makeUserWithRole(
                'Store Admin Request Failure QA',
                'storeadmin.request-failure@example.test',
                'staff',
                config('coremarket.access.store_admin_role', 'store_admin')
            );

            $this->mock(CorePilotAddonRequestClient::class, function ($mock): void {
                $mock->shouldReceive('configured')->once()->andReturnTrue();
                $mock->shouldReceive('submit')->once()->andThrow(new \RuntimeException('Sensitive remote detail'));
            });

            $this->actingAs($user)
                ->from(route('addons.index'))
                ->post(route('addons.request'), ['addon_code' => 'pos_module'])
                ->assertRedirect(route('addons.index'))
                ->assertSessionHasErrors([
                    'addon_request' => 'CorePilotOS could not accept the add-on request. Please try again or contact support.',
                ]);
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

    private function persistSyncedCatalog(array $items): void
    {
        DB::table('business_settings')->where('type', config('coremarket.runtime_snapshot.setting_keys.addon_catalog'))->delete();

        DB::table('business_settings')->insert([
            'type' => config('coremarket.runtime_snapshot.setting_keys.addon_catalog'),
            'lang' => null,
            'value' => json_encode(['catalog_version' => '2026-07-12', 'items' => $items]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
