<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminSubscriptionOverviewTest extends TestCase
{
    public function test_store_admin_can_access_my_subscription_page(): void
    {
        DB::beginTransaction();

        try {
            config()->set('coremarket.runtime.applied_plan_code', 'starter');
            config()->set('coremarket.runtime.store_mode', 'single_store');

            $user = $this->makeUserWithRole(
                'Store Admin Subscription QA',
                'storeadmin.subscription@example.test',
                'staff',
                config('coremarket.access.store_admin_role', 'store_admin')
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

            $user = $this->makeUserWithRole(
                'Store Admin Starter QA',
                'storeadmin.starter@example.test',
                'staff',
                config('coremarket.access.store_admin_role', 'store_admin')
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

            $user = $this->makeUserWithRole(
                'Owner Marketplace QA',
                'owner.subscription@example.test',
                'admin',
                'Marketplace Owner'
            );

            $response = $this->actingAs($user)->get(route('subscription.index'));

            $response
                ->assertOk()
                ->assertSee('Enabled features')
                ->assertSee('Multi Vendor')
                ->assertSee('Sellers')
                ->assertSee('Sellers Limit')
                ->assertSee('1000');
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
