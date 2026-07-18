<?php

namespace Tests\Feature;

use App\Models\LoyaltyAccount;
use App\Models\LoyaltyPointMovement;
use App\Models\LoyaltyRule;
use App\Models\Order;
use App\Models\User;
use App\Services\CoreMarketFeatureAccessService;
use App\Services\LoyaltyPointsService;
use Database\Seeders\OperationsPermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\InteractsWithCoreMarketTestSchema;
use Tests\TestCase;

class LoyaltyPointsUiTest extends TestCase
{
    use InteractsWithCoreMarketTestSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('coremarket_testing', DB::getDatabaseName());
        $this->assertTrue(Schema::hasTable('loyalty_accounts'));
        $this->assertTrue(Schema::hasTable('loyalty_point_movements'));
        $this->assertTrue(Schema::hasTable('loyalty_rules'));

        config()->set('coremarket.runtime_snapshot.connection', 'mysql');
        $this->ensureBusinessSettingsTable();
        $this->ensurePermissionTables();
        $this->ensureLegacyUserColumns();
        $this->ensureAdminSupportTables();
        $this->setLoyaltyFeature(true);
    }

    public function test_disabled_feature_hides_sidebar_and_blocks_direct_urls_for_every_role(): void
    {
        DB::beginTransaction();

        try {
            $staff = $this->staff(['loyalty.view', 'loyalty.rules.manage', 'loyalty.movements.view']);
            $admin = $this->admin();
            $this->setLoyaltyFeature(false);

            $this->actingAs($staff);
            $this->assertStringNotContainsString('Loyalty', view('backend.inc.admin_sidenav')->render());
            foreach ($this->directRoutes() as $route) {
                $this->get($route)->assertNotFound();
            }

            $this->actingAs($admin)->get(route('operations.loyalty.dashboard'))->assertNotFound();
        } finally {
            DB::rollBack();
        }
    }

    public function test_permissions_protect_each_loyalty_page_and_action(): void
    {
        DB::beginTransaction();

        try {
            $customer = $this->customer();
            $account = app(LoyaltyPointsService::class)->accountForCustomer($customer);
            $none = $this->staff([]);
            $viewOnly = $this->staff(['loyalty.view']);
            $rulesOnly = $this->staff(['loyalty.rules.manage']);

            $this->actingAs($none)->get(route('operations.loyalty.dashboard'))->assertForbidden();
            $this->actingAs($none)->get(route('operations.loyalty.accounts.index'))->assertForbidden();
            $this->actingAs($viewOnly)->get(route('operations.loyalty.dashboard'))->assertOk();
            $this->actingAs($viewOnly)->get(route('operations.loyalty.rules'))->assertForbidden();
            $this->actingAs($viewOnly)->get(route('operations.loyalty.movements.index'))->assertForbidden();
            $this->actingAs($viewOnly)->post(route('operations.loyalty.adjust', $account), ['points' => 1, 'reason' => 'No access'])->assertForbidden();
            $this->actingAs($rulesOnly)->get(route('operations.loyalty.rules'))->assertOk();
            $this->actingAs($viewOnly)->post(route('operations.loyalty.rules.store'), $this->rulePayload())->assertForbidden();
        } finally {
            DB::rollBack();
        }
    }

    public function test_permissions_are_seeded_and_sidebar_is_visible_only_when_allowed(): void
    {
        DB::beginTransaction();

        try {
            $this->seed(OperationsPermissionSeeder::class);
            foreach (['loyalty.view', 'loyalty.rules.manage', 'loyalty.adjust', 'loyalty.movements.view'] as $permission) {
                $this->assertDatabaseHas('permissions', ['name' => $permission, 'section' => 'operations']);
            }

            $user = $this->staff(['loyalty.view', 'loyalty.rules.manage', 'loyalty.movements.view']);
            $this->actingAs($user);
            $html = view('backend.inc.admin_sidenav')->render();
            $this->assertStringContainsString('Loyalty', $html);
            $this->assertStringContainsString(route('operations.loyalty.dashboard'), $html);
            $this->assertStringContainsString(route('operations.loyalty.rules'), $html);
            $this->assertStringContainsString(route('operations.loyalty.movements.index'), $html);

            $this->actingAs($this->staff([]));
            $this->assertStringNotContainsString('Loyalty', view('backend.inc.admin_sidenav')->render());
        } finally {
            DB::rollBack();
        }
    }

    public function test_dashboard_and_earn_only_rules_can_be_rendered_created_and_updated(): void
    {
        DB::beginTransaction();

        try {
            $user = $this->staff(['loyalty.view', 'loyalty.rules.manage']);
            $this->actingAs($user)->get(route('operations.loyalty.dashboard'))->assertOk()->assertSee('Loyalty Dashboard');
            $this->actingAs($user)->get(route('operations.loyalty.rules'))->assertOk()->assertSee('Loyalty Earn Rules');

            $payload = $this->rulePayload(['name' => 'UI Earn Rule', 'redeem_points' => 100, 'redeem_value' => 10]);
            $this->actingAs($user)->post(route('operations.loyalty.rules.store'), $payload)->assertRedirect(route('operations.loyalty.rules'));
            $rule = LoyaltyRule::query()->where('name', 'UI Earn Rule')->firstOrFail();
            $this->assertSame(2, $rule->earn_rate_points);
            $this->assertNull($rule->redeem_points);
            $this->assertNull($rule->redeem_value);
            $this->assertFalse($rule->hasRedemptionEnabledForOrderFrom('pos'));

            $this->actingAs($user)->post(route('operations.loyalty.rules.store'), $this->rulePayload([
                'rule_id' => $rule->id,
                'name' => 'UI Earn Rule Updated',
                'is_active' => false,
            ]))->assertRedirect(route('operations.loyalty.rules'));
            $this->assertDatabaseHas('loyalty_rules', ['id' => $rule->id, 'name' => 'UI Earn Rule Updated', 'is_active' => 0]);
        } finally {
            DB::rollBack();
        }
    }

    public function test_accounts_adjustments_and_ledger_filters_use_the_service_without_side_effects(): void
    {
        DB::beginTransaction();

        try {
            $user = $this->staff(['loyalty.view', 'loyalty.adjust', 'loyalty.movements.view']);
            $customer = $this->customer();
            $account = app(LoyaltyPointsService::class)->accountForCustomer($customer);
            $walletsBefore = DB::table('wallets')->count();
            $journalsBefore = DB::table('journal_entries')->count();

            $this->actingAs($user)->get(route('operations.loyalty.accounts.index'))->assertOk()->assertSee('Loyalty Customer Balances');
            $this->actingAs($user)->get(route('operations.loyalty.accounts.show', $account))->assertOk()->assertSee('Manual Adjustment');
            $this->actingAs($user)->post(route('operations.loyalty.adjust', $account), ['points' => 9, 'reason' => 'UI welcome'])->assertRedirect();
            $this->actingAs($user)->post(route('operations.loyalty.adjust', $account), ['points' => -4, 'reason' => 'UI correction'])->assertRedirect();
            $this->actingAs($user)->post(route('operations.loyalty.adjust', $account), ['points' => -6, 'reason' => 'Too much'])->assertSessionHasErrors('points');

            $this->assertSame(5, (int) $account->fresh()->points_balance);
            $this->assertDatabaseHas('loyalty_point_movements', ['loyalty_account_id' => $account->id, 'movement_type' => 'adjustment', 'direction' => 'in', 'points' => 9]);
            $this->assertDatabaseHas('loyalty_point_movements', ['loyalty_account_id' => $account->id, 'movement_type' => 'adjustment', 'direction' => 'out', 'points' => 4]);
            $this->assertSame($walletsBefore, DB::table('wallets')->count());
            $this->assertSame($journalsBefore, DB::table('journal_entries')->count());

            $this->actingAs($user)->get(route('operations.loyalty.movements.index', ['user_id' => $customer->id, 'movement_type' => 'adjustment', 'direction' => 'out']))
                ->assertOk()->assertSee('UI correction')->assertDontSee('UI welcome');
        } finally {
            DB::rollBack();
        }
    }

    public function test_order_trace_shows_earn_and_reverse_movements(): void
    {
        DB::beginTransaction();

        try {
            $user = $this->staff(['loyalty.view', 'loyalty.movements.view']);
            $customer = $this->customer();
            $this->rule();
            $order = $this->order($customer);
            $service = app(LoyaltyPointsService::class);
            $service->earnForOrder($order);
            $service->reverseForOrder($order, 'UI trace reversal', $user);

            $this->actingAs($user)->get(route('operations.loyalty.orders.show', $order))
                ->assertOk()
                ->assertSee('Order Loyalty Trace')
                ->assertSee('Earn')
                ->assertSee('Reverse')
                ->assertSee('UI trace reversal');

            $this->actingAs($user)->get(route('operations.loyalty.movements.index', [
                'reference_type' => Order::class,
                'reference_id' => $order->id,
            ]))->assertOk()->assertSee('UI trace reversal');
        } finally {
            DB::rollBack();
        }
    }

    private function setLoyaltyFeature(bool $enabled): void
    {
        config()->set('coremarket.features.loyalty_points', $enabled);
        app()->forgetInstance(CoreMarketFeatureAccessService::class);
    }

    private function directRoutes(): array
    {
        return [
            route('operations.loyalty.dashboard'),
            route('operations.loyalty.rules'),
            route('operations.loyalty.accounts.index'),
            route('operations.loyalty.movements.index'),
        ];
    }

    private function rulePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Loyalty UI Rule ' . uniqid(),
            'is_active' => true,
            'earn_rate_amount' => 10,
            'earn_rate_points' => 2,
            'min_order_amount' => 0,
            'currency' => 'USD',
            'applies_to_order_from' => 'web',
        ], $overrides);
    }

    private function rule(): LoyaltyRule
    {
        return LoyaltyRule::query()->create($this->rulePayload());
    }

    private function order(User $customer): Order
    {
        $id = DB::table('orders')->insertGetId([
            'user_id' => $customer->id,
            'shipping_type' => 'home_delivery',
            'order_from' => 'web',
            'delivery_status' => 'delivered',
            'payment_type' => 'cash_on_delivery',
            'payment_status' => 'paid',
            'grand_total' => 20,
            'coupon_discount' => 0,
            'code' => 'LOY-UI-' . uniqid(),
            'date' => now()->timestamp,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Order::query()->findOrFail($id);
    }

    private function customer(): User
    {
        return $this->makeUser('customer', []);
    }

    private function admin(): User
    {
        return $this->makeUser('admin', []);
    }

    private function staff(array $permissions): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::query()->firstOrCreate(['name' => 'Loyalty UI ' . uniqid(), 'guard_name' => 'web']);
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }

        $user = $this->makeUser('staff', []);
        $user->assignRole($role);

        return $user;
    }

    private function makeUser(string $type, array $attributes): User
    {
        $user = new User();
        $user->forceFill(array_merge([
            'name' => 'Loyalty UI ' . ucfirst($type),
            'email' => uniqid('loyalty-ui-' . $type) . '@example.test',
            'password' => bcrypt('Temporary123!'),
            'user_type' => $type,
            'email_verified_at' => now(),
        ], $attributes))->save();

        return $user;
    }
}
