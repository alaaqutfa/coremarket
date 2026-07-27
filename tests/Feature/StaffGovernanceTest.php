<?php

namespace Tests\Feature;

use App\Http\Controllers\RoleController;
use App\Http\Controllers\StaffController;
use App\Models\Staff;
use App\Models\User;
use App\Services\CoreMarketFeatureAccessService;
use App\Services\CoreMarketStaffGovernanceService;
use Database\Seeders\StaffRolePresetSeeder;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StaffGovernanceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_client_admin_can_assign_only_approved_presets_and_cannot_manage_permissions(): void
    {
        $this->seed(StaffRolePresetSeeder::class);
        $service = app(CoreMarketStaffGovernanceService::class);
        $actor = $this->user('owner-' . uniqid() . '@example.test');
        $actor->syncRoles('owner_general_manager');
        $target = $this->user('target-' . uniqid() . '@example.test');

        $service->assignPresetToUser($target, Role::findByName('cashier')->id, $actor);

        $this->assertTrue($target->fresh()->hasRole('cashier'));
        $this->assertFalse($service->canManageRawPermissions($actor));
        $this->actingAs($actor);
        try {
            app(RoleController::class)->index();
            $this->fail('Client administrator unexpectedly opened raw role management.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->expectException(DomainException::class);
        $service->assignPresetToUser($target, Role::findByName('Super Admin')->id, $actor);
    }

    public function test_client_admin_can_suspend_but_cannot_hard_delete_staff(): void
    {
        $this->seed(StaffRolePresetSeeder::class);
        $actor = $this->user('manager-' . uniqid() . '@example.test');
        $actor->syncRoles('owner_general_manager');
        $target = $this->user('staff-' . uniqid() . '@example.test');
        $role = Role::findByName('cashier');
        $target->syncRoles($role);
        $staff = $this->staff($target, $role->id);

        $this->actingAs($actor);
        app(StaffController::class)->suspend($staff, app(CoreMarketStaffGovernanceService::class));
        $this->assertSame(1, (int) $target->fresh()->banned);
        try {
            app(StaffController::class)->destroy($staff->id, app(CoreMarketStaffGovernanceService::class));
            $this->fail('Client administrator unexpectedly hard-deleted staff.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_staff_plan_limit_blocks_client_creation_and_platform_admin_keeps_governance_access(): void
    {
        $features = $this->mock(CoreMarketFeatureAccessService::class);
        $features->shouldReceive('limit')->with('staff_limit')->andReturn(1);
        $service = new CoreMarketStaffGovernanceService($features);
        $actor = $this->user('limit-owner-' . uniqid() . '@example.test');
        $existing = $this->user('existing-' . uniqid() . '@example.test');
        $roleId = Role::query()->where('name', '!=', 'Super Admin')->value('id');
        $this->staff($existing, $roleId);
        $platform = $this->user('platform-' . uniqid() . '@example.test', 'admin');

        $this->assertSame(1, $service->staffLimit());
        $this->assertGreaterThanOrEqual(1, $service->currentStaffCount());
        $this->assertFalse($service->canCreateStaff($actor));
        $this->assertTrue($service->canCreateStaff($platform));
        $this->assertTrue($service->canManageRawPermissions($platform));
        $this->assertTrue($service->canDeleteStaff($platform));
    }

    private function user(string $email, string $type = 'staff'): User
    {
        $user = User::query()->create([
            'name' => 'Governance User',
            'email' => $email,
            'password' => bcrypt('Temporary123!'),
        ]);
        $user->forceFill(['user_type' => $type, 'banned' => 0])->save();

        return $user;
    }

    private function staff(User $user, int $roleId): Staff
    {
        $staff = new Staff();
        $staff->user_id = $user->id;
        $staff->role_id = $roleId;
        $staff->save();

        return $staff;
    }
}
