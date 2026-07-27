<?php

namespace Tests\Feature;

use App\Models\StoreBranch;
use App\Models\User;
use App\Services\CoreMarketBranchService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BranchFoundationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_default_branch_is_resolved_and_policies_are_safe_defaults(): void
    {
        $this->assertSame('coremarket_testing', DB::getDatabaseName());
        DB::table('business_settings')->whereIn('type', [
            'branches.enabled', 'branches.price_policy', 'branches.inventory_policy',
        ])->delete();
        Cache::forget('business_settings');
        $service = app(CoreMarketBranchService::class);
        $branch = $service->ensureDefaultBranch();

        $this->assertTrue($branch->is_default);
        $this->assertSame($branch->id, $service->resolveBranch()->id);
        $this->assertFalse($service->branchesEnabled());
        $this->assertSame('unified', $service->pricePolicy());
        $this->assertSame('unified', $service->inventoryPolicy());
    }

    public function test_staff_can_have_primary_branch_and_manager_role_has_all_branch_scope(): void
    {
        $service = app(CoreMarketBranchService::class);
        $main = $service->ensureDefaultBranch();
        $second = StoreBranch::query()->create([
            'name' => 'North Branch',
            'code' => 'NORTH-' . uniqid(),
            'is_active' => true,
        ]);
        $user = $this->staffUser('branch-staff-' . uniqid() . '@example.test');

        $service->assignStaff($user, [$main->id, $second->id], $second->id);

        $this->assertCount(2, $user->fresh()->branches);
        $this->assertTrue($service->activeBranches()->contains('id', $second->id));
        $this->assertSame($second->id, $user->fresh()->branches->first(fn ($branch) => (bool) $branch->pivot->is_primary)->id);
        $role = Role::query()->firstOrCreate(['name' => 'owner_general_manager', 'guard_name' => 'web']);
        $user->syncRoles($role);
        $this->assertTrue($service->userHasAllBranches($user->fresh()));
    }

    private function staffUser(string $email): User
    {
        return User::query()->create([
            'name' => 'Branch Staff',
            'email' => $email,
            'password' => bcrypt('Temporary123!'),
            'user_type' => 'staff',
            'banned' => 0,
        ]);
    }
}
