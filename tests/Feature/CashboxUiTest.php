<?php

namespace Tests\Feature;

use App\Models\Cashbox;
use App\Models\CashierShift;
use App\Models\User;
use App\Services\CoreMarketFeatureAccessService;
use Database\Seeders\OperationsPermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\InteractsWithCoreMarketTestSchema;
use Tests\TestCase;

class CashboxUiTest extends TestCase
{
    use InteractsWithCoreMarketTestSchema;

    private array $allPermissions = [
        'cashboxes.view',
        'cashboxes.create',
        'cashboxes.edit',
        'cash_shifts.view',
        'cash_shifts.open',
        'cash_shifts.close',
        'cash_movements.view',
        'cash_movements.create',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('coremarket_testing', DB::getDatabaseName());
        $this->assertTrue(Schema::hasTable('cashboxes'));
        $this->assertTrue(Schema::hasTable('cashier_shifts'));
        $this->assertTrue(Schema::hasTable('cash_movements'));

        config()->set('coremarket.runtime_snapshot.connection', 'mysql');
        $this->ensureBusinessSettingsTable();
        $this->ensurePermissionTables();
        $this->ensureLegacyUserColumns();
        $this->ensureAdminSupportTables();
        $this->setCashboxFeature(true);
    }

    public function test_dashboard_is_protected_by_permission_and_feature_gate(): void
    {
        DB::beginTransaction();

        try {
            $this->actingAs($this->user([]))
                ->get(route('operations.cashbox.dashboard'))
                ->assertForbidden();

            $authorized = $this->user(['cashboxes.view']);
            $this->actingAs($authorized)
                ->get(route('operations.cashbox.dashboard'))
                ->assertOk()
                ->assertSee('Cashbox Dashboard');

            $this->setCashboxFeature(false);
            $this->actingAs($authorized)
                ->get(route('operations.cashbox.dashboard'))
                ->assertNotFound();
        } finally {
            DB::rollBack();
        }
    }

    public function test_cashbox_permissions_and_sidebar_visibility_are_feature_gated(): void
    {
        DB::beginTransaction();

        try {
            $this->seed(OperationsPermissionSeeder::class);
            $this->assertDatabaseHas('permissions', ['name' => 'cashboxes.view', 'section' => 'operations']);
            $this->assertDatabaseHas('permissions', ['name' => 'cash_shifts.open', 'section' => 'operations']);
            $this->assertDatabaseHas('permissions', ['name' => 'cash_movements.create', 'section' => 'operations']);

            $authorized = $this->user(['cashboxes.view', 'cash_shifts.view', 'cash_movements.view']);
            $this->actingAs($authorized);
            $html = view('backend.inc.admin_sidenav')->render();
            $this->assertStringContainsString('Cashbox', $html);
            $this->assertStringContainsString(route('operations.cashbox.dashboard'), $html);
            $this->assertStringContainsString(route('operations.cashboxes'), $html);
            $this->assertStringContainsString(route('operations.cash-shifts'), $html);
            $this->assertStringContainsString(route('operations.cash-movements'), $html);

            $this->setCashboxFeature(false);
            $this->assertStringNotContainsString('Cashbox', view('backend.inc.admin_sidenav')->render());

            $this->setCashboxFeature(true);
            $this->actingAs($this->user([]));
            $this->assertStringNotContainsString('Cashbox', view('backend.inc.admin_sidenav')->render());
        } finally {
            DB::rollBack();
        }
    }

    public function test_cashbox_crud_pages_actions_filters_and_sidebar_active_state_work(): void
    {
        DB::beginTransaction();

        try {
            $user = $this->user(['cashboxes.view', 'cashboxes.create', 'cashboxes.edit']);
            $assignedUser = $this->user([]);

            $this->actingAs($user)->get(route('operations.cashboxes'))
                ->assertOk()
                ->assertSee('Cashboxes');
            $this->actingAs($user)->get(route('operations.cashboxes.create'))
                ->assertOk()
                ->assertSee('Create Cashbox');

            $this->actingAs($user)->post(route('operations.cashboxes.store'), [
                'name' => 'UI Main Cashbox',
                'code' => 'UI-CASH-' . uniqid(),
                'location' => 'Front desk',
                'currency' => 'USD',
                'status' => 'active',
                'assigned_user_id' => $assignedUser->id,
            ])->assertRedirect();

            $cashbox = Cashbox::query()->where('name', 'UI Main Cashbox')->firstOrFail();
            $this->actingAs($user)->get(route('operations.cashboxes.show', $cashbox))
                ->assertOk()
                ->assertSee('UI Main Cashbox');
            $this->actingAs($user)->get(route('operations.cashboxes.edit', $cashbox))
                ->assertOk()
                ->assertSee('Edit Cashbox');
            $this->actingAs($user)->put(route('operations.cashboxes.update', $cashbox), [
                'name' => 'UI Updated Cashbox',
                'code' => $cashbox->code,
                'location' => 'Back desk',
                'currency' => 'USD',
                'status' => 'inactive',
                'assigned_user_id' => $assignedUser->id,
            ])->assertRedirect(route('operations.cashboxes.show', $cashbox));

            $this->assertDatabaseHas('cashboxes', ['id' => $cashbox->id, 'name' => 'UI Updated Cashbox', 'status' => 'inactive']);
            $other = $this->cashbox('Other Cashbox', 'active');
            $this->actingAs($user)->get(route('operations.cashboxes', ['search' => 'Updated Cashbox']))->assertOk()->assertSee('UI Updated Cashbox')->assertDontSee('Other Cashbox');
            $this->actingAs($user)->get(route('operations.cashboxes', ['status' => 'inactive']))->assertOk()->assertSee('UI Updated Cashbox')->assertDontSee($other->name);
            $this->actingAs($user)->get(route('operations.cashboxes', ['assigned_user_id' => $assignedUser->id]))->assertOk()->assertSee('UI Updated Cashbox');

            $response = $this->actingAs($user)->get(route('operations.cashboxes'));
            $response->assertSee(route('operations.cashboxes'));
            $this->assertStringContainsString('active', $response->getContent());
        } finally {
            DB::rollBack();
        }
    }

    public function test_shift_pages_open_close_read_only_and_filters_work(): void
    {
        DB::beginTransaction();

        try {
            $user = $this->user(['cashboxes.view', 'cash_shifts.view', 'cash_shifts.open', 'cash_shifts.close', 'cash_movements.create']);
            $cashbox = $this->cashbox('Shift UI Cashbox');

            $this->actingAs($user)->get(route('operations.cash-shifts'))->assertOk()->assertSee('Cashier Shifts');
            $this->actingAs($user)->get(route('operations.cash-shifts.open.form', $cashbox))->assertOk()->assertSee('Open Cashier Shift');
            $this->actingAs($user)->post(route('operations.cash-shifts.open', $cashbox), ['opening_balance' => 20, 'notes' => 'UI opening'])->assertRedirect();

            $shift = CashierShift::query()->where('cashbox_id', $cashbox->id)->firstOrFail();
            $this->actingAs($user)->get(route('operations.cash-shifts.show', $shift))->assertOk()->assertSee('Cashier Shift')->assertSee('Add Cash Movement');
            $this->actingAs($user)->get(route('operations.cash-shifts.close.form', $shift))->assertOk()->assertSee('Close Cashier Shift');
            $this->actingAs($user)->post(route('operations.cash-shifts.close', $shift), ['actual_cash' => 18, 'close_notes' => 'UI close'])->assertRedirect(route('operations.cash-shifts.show', $shift));

            $this->actingAs($user)->get(route('operations.cash-shifts.show', $shift))->assertOk()->assertDontSee('Add Cash Movement')->assertDontSee('Close Shift');
            $this->actingAs($user)->get(route('operations.cash-movements.create', $shift))->assertStatus(409);
            $this->actingAs($user)->get(route('operations.cash-shifts.close.form', $shift))->assertStatus(409);
            $this->actingAs($user)->post(route('operations.cash-movements.store', $shift), ['movement_type' => 'cash_in', 'direction' => 'in', 'amount' => 1, 'description' => 'After close'])->assertSessionHasErrors('movement');

            $inactive = $this->cashbox('Inactive UI Cashbox', 'inactive');
            $this->actingAs($user)->post(route('operations.cash-shifts.open', $inactive), ['opening_balance' => 10])->assertSessionHasErrors('shift');

            $this->actingAs($user)->get(route('operations.cash-shifts', ['status' => 'closed']))->assertOk()->assertSee('Closed');
            $this->actingAs($user)->get(route('operations.cash-shifts', ['cashbox_id' => $cashbox->id]))->assertOk()->assertSee($cashbox->name);
            $this->actingAs($user)->get(route('operations.cash-shifts', ['opened_by' => $user->id]))->assertOk()->assertSee($cashbox->name);
            $this->actingAs($user)->get(route('operations.cash-shifts', ['has_difference' => 1]))->assertOk()->assertSee($cashbox->name);
            $this->actingAs($user)->get(route('operations.cash-shifts', ['date_from' => now()->toDateString(), 'date_to' => now()->toDateString()]))->assertOk()->assertSee($cashbox->name);
            $this->actingAs($user)->get(route('operations.cash-shifts', ['open_only' => 1]))->assertOk()->assertSee('No cashier shifts found.');
        } finally {
            DB::rollBack();
        }
    }

    public function test_cash_movement_forms_actions_filters_and_domain_safety_work(): void
    {
        DB::beginTransaction();

        try {
            $user = $this->user(['cashboxes.view', 'cash_shifts.open', 'cash_shifts.view', 'cash_movements.view', 'cash_movements.create']);
            $cashbox = $this->cashbox('Movement UI Cashbox');
            $this->actingAs($user)->post(route('operations.cash-shifts.open', $cashbox), ['opening_balance' => 50])->assertRedirect();
            $shift = CashierShift::query()->where('cashbox_id', $cashbox->id)->firstOrFail();

            $this->actingAs($user)->get(route('operations.cash-movements.create', $shift))->assertOk()->assertSee('Add Cash Movement');
            $journalCount = DB::table('journal_entries')->count();
            $inventoryCount = DB::table('inventory_movements')->count();
            $orderCount = DB::table('orders')->count();

            $this->actingAs($user)->post(route('operations.cash-movements.store', $shift), ['movement_type' => 'cash_in', 'direction' => 'in', 'amount' => 10, 'description' => 'UI cash in'])->assertRedirect(route('operations.cash-shifts.show', $shift));
            $this->actingAs($user)->post(route('operations.cash-movements.store', $shift), ['movement_type' => 'cash_out', 'direction' => 'out', 'amount' => 5, 'description' => 'UI cash out'])->assertRedirect();
            $this->actingAs($user)->post(route('operations.cash-movements.store', $shift), ['movement_type' => 'adjustment', 'direction' => 'neutral', 'amount' => 2, 'description' => 'UI adjustment'])->assertRedirect();

            $this->assertSame($journalCount, DB::table('journal_entries')->count());
            $this->assertSame($inventoryCount, DB::table('inventory_movements')->count());
            $this->assertSame($orderCount, DB::table('orders')->count());

            $this->actingAs($user)->get(route('operations.cash-movements'))->assertOk()->assertSee('Cash Movements')->assertSee('UI cash in');
            $this->actingAs($user)->get(route('operations.cash-movements', ['cashbox_id' => $cashbox->id]))->assertOk()->assertSee('UI cash in');
            $this->actingAs($user)->get(route('operations.cash-movements', ['cashier_shift_id' => $shift->id]))->assertOk()->assertSee('UI cash out');
            $this->actingAs($user)->get(route('operations.cash-movements', ['movement_type' => 'cash_in']))->assertOk()->assertSee('UI cash in')->assertDontSee('UI cash out');
            $this->actingAs($user)->get(route('operations.cash-movements', ['direction' => 'out']))->assertOk()->assertSee('UI cash out')->assertDontSee('UI cash in');
            $this->actingAs($user)->get(route('operations.cash-movements', ['created_by' => $user->id]))->assertOk()->assertSee('UI adjustment');
            $this->actingAs($user)->get(route('operations.cash-movements', ['date_from' => now()->toDateString(), 'date_to' => now()->toDateString()]))->assertOk()->assertSee('UI cash in');
        } finally {
            DB::rollBack();
        }
    }

    private function setCashboxFeature(bool $enabled): void
    {
        config()->set('coremarket.features.cashbox_shifts', $enabled);
        app()->forgetInstance(CoreMarketFeatureAccessService::class);
    }

    private function user(array $permissions): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::query()->firstOrCreate(['name' => 'Cashbox UI ' . uniqid(), 'guard_name' => 'web']);

        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }

        $user = new User();
        $user->forceFill([
            'name' => 'Cashbox UI User',
            'email' => uniqid('cashbox-ui') . '@example.test',
            'password' => bcrypt('Temporary123!'),
            'user_type' => 'staff',
            'email_verified_at' => now(),
        ])->save();
        $user->assignRole($role);

        return $user;
    }

    private function cashbox(string $name, string $status = 'active'): Cashbox
    {
        return Cashbox::query()->create([
            'name' => $name,
            'code' => 'UI-' . uniqid(),
            'currency' => 'USD',
            'status' => $status,
        ]);
    }
}
