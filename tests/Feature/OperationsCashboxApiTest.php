<?php

namespace Tests\Feature;

use App\Models\CashMovement;
use App\Models\Cashbox;
use App\Models\CashierShift;
use App\Models\User;
use App\Services\CashboxService;
use App\Services\CoreMarketFeatureAccessService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\InteractsWithCoreMarketTestSchema;
use Tests\TestCase;

class OperationsCashboxApiTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithCoreMarketTestSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('coremarket_testing', DB::getDatabaseName());
        $this->assertTrue(Schema::hasTable('cash_movements'));
        config()->set('coremarket.runtime_snapshot.connection', 'mysql');
        $this->ensureBusinessSettingsTable();
        $this->ensurePermissionTables();
        $this->ensureLegacyUserColumns();
        $this->ensureAdminSupportTables();
        $this->clearPersistedRuntimeFeatures();
        config()->set('coremarket.features.pos', true);
        config()->set('coremarket.features.cashbox_shifts', true);
        app()->forgetInstance(CoreMarketFeatureAccessService::class);
    }

    public function test_cashboxes_and_current_shift_respect_assignment_and_visibility(): void
    {
        $staff = $this->user(['cashboxes.view', 'pos.view']);
        $other = $this->user([]);
        $unassigned = $this->cashbox('Unassigned');
        $mine = $this->cashbox('Mine', ['assigned_user_id' => $staff->id]);
        $others = $this->cashbox('Others', ['assigned_user_id' => $other->id]);
        $this->cashbox('Inactive', ['status' => 'inactive']);

        Sanctum::actingAs($staff, ['operations:pos']);
        $this->getJson(route('api.v2.operations.cashboxes.index'))
            ->assertOk()
            ->assertJsonFragment(['id' => $unassigned->id])
            ->assertJsonFragment(['id' => $mine->id])
            ->assertJsonMissing(['id' => $others->id]);
        $this->getJson(route('api.v2.operations.cash_shifts.current'))
            ->assertOk()
            ->assertJsonPath('data.has_open_shift', false);

        $shift = app(CashboxService::class)->openShift($mine, $staff, 12);
        $this->getJson(route('api.v2.operations.cash_shifts.current'))
            ->assertOk()
            ->assertJsonPath('data.has_open_shift', true)
            ->assertJsonPath('data.shift.id', $shift->id)
            ->assertJsonPath('data.expected_cash', 12);

        $admin = $this->user([], 'admin');
        Sanctum::actingAs($admin, ['operations:pos']);
        $this->getJson(route('api.v2.operations.cashboxes.index'))
            ->assertOk()
            ->assertJsonFragment(['id' => $unassigned->id])
            ->assertJsonFragment(['id' => $mine->id])
            ->assertJsonFragment(['id' => $others->id]);
    }

    public function test_open_and_close_shift_follow_cashbox_rules_without_other_domain_side_effects(): void
    {
        $user = $this->user(['cash_shifts.open', 'cash_shifts.close']);
        $other = $this->user([]);
        $inactive = $this->cashbox('Inactive', ['status' => 'inactive']);
        $assignedElsewhere = $this->cashbox('Assigned Elsewhere', ['assigned_user_id' => $other->id]);
        $cashbox = $this->cashbox('Main');
        $secondCashbox = $this->cashbox('Second');
        $journals = DB::table('journal_entries')->count();
        $inventory = DB::table('inventory_movements')->count();
        $orders = DB::table('orders')->count();

        Sanctum::actingAs($user, ['operations:pos']);
        $this->postJson(route('api.v2.operations.cash_shifts.open', $inactive), ['opening_cash' => 0])->assertConflict();
        $this->postJson(route('api.v2.operations.cash_shifts.open', $assignedElsewhere), ['opening_cash' => 0])->assertForbidden();

        $open = $this->postJson(route('api.v2.operations.cash_shifts.open', $cashbox), ['opening_cash' => 10, 'note' => 'API opening'])
            ->assertCreated()
            ->assertJsonPath('message', 'Shift opened')
            ->assertJsonPath('data.expected_cash', 10);
        $shift = CashierShift::query()->findOrFail($open->json('data.shift.id'));
        $this->assertDatabaseHas('cash_movements', ['cashier_shift_id' => $shift->id, 'movement_type' => 'opening', 'direction' => 'in', 'amount' => '10.000000']);

        $this->postJson(route('api.v2.operations.cash_shifts.open', $secondCashbox), ['opening_cash' => 0])->assertConflict();
        $this->postJson(route('api.v2.operations.cash_shifts.close', $shift), ['actual_cash' => 7, 'note' => 'API close'])
            ->assertOk()
            ->assertJsonPath('message', 'Shift closed')
            ->assertJsonPath('data.cash_difference', -3);
        $this->assertDatabaseHas('cash_movements', ['cashier_shift_id' => $shift->id, 'movement_type' => 'closing_difference', 'direction' => 'neutral', 'amount' => '3.000000']);
        $this->postJson(route('api.v2.operations.cash_shifts.close', $shift), ['actual_cash' => 7])->assertConflict();

        $this->assertSame($journals, DB::table('journal_entries')->count());
        $this->assertSame($inventory, DB::table('inventory_movements')->count());
        $this->assertSame($orders, DB::table('orders')->count());
    }

    public function test_non_admin_cannot_close_another_users_shift(): void
    {
        $owner = $this->user(['cash_shifts.open']);
        $other = $this->user(['cash_shifts.close']);
        $cashbox = $this->cashbox('Owner Cashbox');
        $shift = app(CashboxService::class)->openShift($cashbox, $owner, 0);

        Sanctum::actingAs($other, ['operations:pos']);
        $this->postJson(route('api.v2.operations.cash_shifts.close', $shift), ['actual_cash' => 0])->assertForbidden();
    }

    private function clearPersistedRuntimeFeatures(): void
    {
        DB::table('business_settings')
            ->where('type', 'coremarket_runtime_features')
            ->delete();

        Cache::forget('business_settings');
        app()->forgetInstance(CoreMarketFeatureAccessService::class);
    }

    private function user(array $permissions, string $type = 'staff'): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::query()->firstOrCreate(['name' => 'Operations Cashbox API ' . uniqid(), 'guard_name' => 'web']);
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }

        $user = new User();
        $user->forceFill([
            'name' => 'Operations Cashbox API User',
            'email' => uniqid('operations-cashbox-api') . '@example.test',
            'password' => bcrypt('Temporary123!'),
            'user_type' => $type,
            'email_verified_at' => now(),
        ])->save();
        $user->assignRole($role);

        return $user;
    }

    private function cashbox(string $name, array $attributes = []): Cashbox
    {
        return app(CashboxService::class)->createCashbox(array_merge([
            'name' => $name,
            'code' => 'CASHBOX-API-' . uniqid(),
            'currency' => 'USD',
            'status' => 'active',
        ], $attributes));
    }
}
