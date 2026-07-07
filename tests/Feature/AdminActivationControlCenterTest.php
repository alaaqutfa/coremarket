<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminActivationControlCenterTest extends TestCase
{
    public function test_owner_admin_can_access_activation_control_center(): void
    {
        DB::beginTransaction();

        try {
            $user = $this->makeAdminUserWithActivationAccess();

            config()->set('coremarket.runtime.applied_plan_code', 'business');
            config()->set('coremarket.runtime.store_mode', 'single_store');

            $response = $this->actingAs($user)->get(route('activation.index'));

            $response
                ->assertOk()
                ->assertSee('Activation control center')
                ->assertSee('Applied plan')
                ->assertSee('Store mode')
                ->assertSee('Products usage')
                ->assertSee('Monthly orders')
                ->assertSee('License snapshot')
                ->assertSee('Enabled runtime features')
                ->assertSee('Runtime limits');
        } finally {
            DB::rollBack();
        }
    }

    public function test_store_admin_cannot_access_activation_control_center_even_with_permission(): void
    {
        DB::beginTransaction();

        try {
            $user = $this->makeStoreAdminUserWithActivationPermission();

            $this->actingAs($user)
                ->get(route('activation.index'))
                ->assertForbidden();
        } finally {
            DB::rollBack();
        }
    }

    public function test_forbidden_activation_page_uses_neutral_error_layout(): void
    {
        DB::beginTransaction();

        try {
            $user = $this->makeStoreAdminUserWithActivationPermission();

            $response = $this->actingAs($user)->get(route('activation.index'));

            $response
                ->assertForbidden()
                ->assertSee('Access denied')
                ->assertSee('Return to dashboard')
                ->assertDontSee('Subscribe to our newsletter')
                ->assertDontSee('Categories')
                ->assertDontSee('Flash deals');
        } finally {
            DB::rollBack();
        }
    }

    public function test_activation_page_hides_legacy_unsafe_activation_controls(): void
    {
        DB::beginTransaction();

        try {
            $user = $this->makeAdminUserWithActivationAccess();

            $response = $this->actingAs($user)->get(route('activation.index'));

            $response
                ->assertOk()
                ->assertDontSee('HTTPS Activation')
                ->assertDontSee('Maintenance Mode Activation')
                ->assertDontSee('Wallet System Activation')
                ->assertSee('Unsafe legacy vendor and environment toggles are intentionally hidden from this page.');
        } finally {
            DB::rollBack();
        }
    }

    private function makeAdminUserWithActivationAccess(): User
    {
        $this->seedActivationPermissionFixtures();

        $user = new User();
        $user->forceFill([
            'name' => 'Activation Owner',
            'email' => 'activation.owner@example.test',
            'password' => bcrypt('Temporary123!'),
            'user_type' => 'admin',
            'email_verified_at' => now(),
        ])->save();

        $user->assignRole('Activation Owner');

        return $user;
    }

    private function makeStoreAdminUserWithActivationPermission(): User
    {
        $this->seedActivationPermissionFixtures();

        $user = new User();
        $user->forceFill([
            'name' => 'Activation Store Admin',
            'email' => 'activation.storeadmin@example.test',
            'password' => bcrypt('Temporary123!'),
            'user_type' => 'staff',
            'email_verified_at' => now(),
        ])->save();

        $user->assignRole(config('coremarket.access.store_admin_role', 'store_admin'));
        $user->givePermissionTo('features_activation');

        return $user;
    }

    private function seedActivationPermissionFixtures(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::query()->firstOrCreate([
            'name' => 'features_activation',
            'guard_name' => 'web',
        ]);

        $ownerRole = Role::query()->firstOrCreate([
            'name' => 'Activation Owner',
            'guard_name' => 'web',
        ]);
        $ownerRole->givePermissionTo($permission);

        Role::query()->firstOrCreate([
            'name' => config('coremarket.access.store_admin_role', 'store_admin'),
            'guard_name' => 'web',
        ]);
    }
}
