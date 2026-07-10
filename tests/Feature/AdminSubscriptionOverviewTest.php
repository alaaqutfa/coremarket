<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Support\InteractsWithCoreMarketTestSchema;

class AdminSubscriptionOverviewTest extends TestCase
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

    public function test_store_admin_can_access_my_subscription_page(): void
    {
        DB::beginTransaction();

        try {
            config()->set('coremarket.runtime.applied_plan_code', 'starter');
            config()->set('coremarket.runtime.store_mode', 'single_store');

            $user = $this->makeUserWithRoleAndPermissions(
                'Store Admin Subscription QA',
                'storeadmin.subscription@example.test',
                'staff',
                config('coremarket.access.store_admin_role', 'store_admin'),
                [
                    'show_in_house_products',
                    'view_product_categories',
                    'view_inhouse_orders',
                    'header_setup',
                    'footer_setup',
                ]
            );

            $this->actingAs($user)
                ->get(route('subscription.index'))
                ->assertOk()
                ->assertSee('My Subscription')
                ->assertSee('Managed by CorePilotOS')
                ->assertSee('Applied plan')
                ->assertSee('Store mode')
                ->assertSee('Runtime limits')
                ->assertSee('Usage snapshot')
                ->assertSee('Media storage limit (MB)')
                ->assertSee('Store Operations')
                ->assertSee('Manage Products')
                ->assertSee('Manage Categories')
                ->assertSee('View Orders')
                ->assertSee('Addon Requests')
                ->assertSee('256');
        } finally {
            DB::rollBack();
        }
    }

    public function test_starter_single_store_page_lists_marketplace_features_as_disabled(): void
    {
        DB::beginTransaction();

        try {
            config()->set('coremarket.runtime.applied_plan_code', 'starter');
            config()->set('coremarket.runtime.store_mode', 'single_store');

            $user = $this->makeUserWithRoleAndPermissions(
                'Store Admin Starter QA',
                'storeadmin.starter@example.test',
                'staff',
                config('coremarket.access.store_admin_role', 'store_admin'),
                ['show_in_house_products']
            );

            $response = $this->actingAs($user)->get(route('subscription.index'));

            $response
                ->assertOk()
                ->assertSee('Disabled features')
                ->assertSee('Multi Vendor')
                ->assertSee('Sellers')
                ->assertSee('Sellers Limit')
                ->assertSee('0');
        } finally {
            DB::rollBack();
        }
    }

    public function test_marketplace_plan_shows_marketplace_features_and_limits(): void
    {
        DB::beginTransaction();

        try {
            config()->set('coremarket.runtime.applied_plan_code', 'marketplace');
            config()->set('coremarket.runtime.store_mode', 'marketplace');

            $user = $this->makeUserWithRoleAndPermissions(
                'Owner Marketplace QA',
                'owner.subscription@example.test',
                'admin',
                'Marketplace Owner',
                [
                    'show_in_house_products',
                    'view_product_categories',
                    'view_inhouse_orders',
                    'header_setup',
                    'footer_setup',
                ]
            );

            $response = $this->actingAs($user)->get(route('subscription.index'));

            $response
                ->assertOk()
                ->assertSee('Enabled features')
                ->assertSee('Multi Vendor')
                ->assertSee('Sellers')
                ->assertSee('Manage Translations')
                ->assertSee('Manage Currency Rates')
                ->assertSee('Sellers Limit')
                ->assertSee('20');
        } finally {
            DB::rollBack();
        }
    }

    private function makeUserWithRoleAndPermissions(
        string $name,
        string $email,
        string $userType,
        string $roleName,
        array $permissions = []
    ): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::query()->firstOrCreate([
            'name' => $roleName,
            'guard_name' => 'web',
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web',
            ]);

            $role->givePermissionTo($permission);
        }

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
