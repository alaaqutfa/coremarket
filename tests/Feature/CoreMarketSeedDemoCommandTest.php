<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\CoreMarketRuntimeDatabaseResolver;
use App\Services\Demo\CoreMarketDemoSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CoreMarketSeedDemoCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_command_refuses_database_without_demo_suffix(): void
    {
        $this->bindDatabase('coremarket_sandbox');

        $this->artisan('coremarket:seed-demo')
            ->expectsOutput('Demo seed refused by the database safety guard.')
            ->expectsOutput('No database changes were made.')
            ->assertExitCode(1);
    }

    public function test_command_explicitly_refuses_runtime_database(): void
    {
        $this->bindDatabase('coremarket_runtime');

        $this->artisan('coremarket:seed-demo', ['--apply' => true, '--confirm-demo-seed' => true])
            ->expectsOutput('Demo seed refused by the database safety guard.')
            ->assertExitCode(1);
    }

    public function test_command_explicitly_refuses_testing_database(): void
    {
        $this->bindDatabase('coremarket_testing');

        $this->artisan('coremarket:seed-demo')
            ->expectsOutput('Demo seed refused by the database safety guard.')
            ->assertExitCode(1);
    }

    public function test_runtime_snapshot_connection_defaults_to_isolated_runtime_database(): void
    {
        $this->assertSame('coremarket_runtime', config('coremarket.runtime_snapshot.connection'));
    }

    public function test_runtime_snapshot_connection_can_be_overridden_to_current_database(): void
    {
        config()->set('coremarket.runtime_snapshot.connection', 'mysql');
        $resolver = new CoreMarketRuntimeDatabaseResolver();

        $this->assertSame('mysql', $resolver->runtimeConnectionName());
        $this->assertSame(DB::connection()->getDatabaseName(), $resolver->resolve()['runtime_database_name']);
    }

    public function test_dry_run_is_default_and_does_not_write_rows_or_baselines(): void
    {
        $this->bindDatabase('coremarket_showroom_demo');
        $before = $this->databaseCounts();
        $baselineHashes = $this->baselineHashes();

        $this->artisan('coremarket:seed-demo')
            ->expectsOutput('CoreMarket protected demo seed plan')
            ->expectsOutput('Dry-run complete. No database changes were made.')
            ->assertExitCode(0);

        $this->assertSame($before, $this->databaseCounts());
        $this->assertSame($baselineHashes, $this->baselineHashes());
    }

    public function test_apply_requires_explicit_confirmation(): void
    {
        $this->bindDatabase('coremarket_showroom_demo');
        $before = $this->databaseCounts();

        $this->artisan('coremarket:seed-demo', ['--apply' => true])
            ->expectsOutput('Apply mode was refused by the demo seed safety guard.')
            ->expectsOutput('No database changes were made.')
            ->assertExitCode(1);

        $this->assertSame($before, $this->databaseCounts());
    }

    public function test_confirmed_apply_creates_a_linked_standard_demo_dataset(): void
    {
        $this->bindDatabase('coremarket_showroom_demo');
        $before = $this->databaseCounts();

        $this->artisan('coremarket:seed-demo', [
            '--apply' => true,
            '--confirm-demo-seed' => true,
            '--with-samples' => 'standard',
        ])
            ->expectsOutput('Final summary')
            ->expectsOutput('Demo seed completed safely.')
            ->assertExitCode(0);

        $after = $this->databaseCounts();
        $this->assertGreaterThan($before['users'], $after['users']);
        $this->assertGreaterThan($before['products'], $after['products']);
        $this->assertGreaterThan($before['orders'], $after['orders']);
        $this->assertGreaterThan($before['loyalty_accounts'], $after['loyalty_accounts']);
        $this->assertGreaterThan($before['cashboxes'], $after['cashboxes']);
        $this->assertSame(2, DB::table('sales_returns')->where('return_number', 'like', 'DEMO-SR-%')->count());
        $this->assertSame(2, DB::table('purchase_receipts')->where('receipt_key', 'like', 'DEMO-PR-%')->count());
        $this->assertSame(0, DB::table('journal_entries')->where('entry_number', 'like', 'DEMO-%')->count());

        $features = json_decode((string) DB::table('business_settings')
            ->where('type', 'coremarket_runtime_features')
            ->whereNull('lang')
            ->value('value'), true);
        $this->assertSame('business', DB::table('business_settings')->where('type', 'coremarket_runtime_applied_plan')->value('value'));
        $this->assertSame('single_store', DB::table('business_settings')->where('type', 'coremarket_runtime_store_mode')->value('value'));
        foreach (['pos', 'cashbox_shifts', 'loyalty_points', 'inventory_pro', 'purchasing_suppliers', 'returns_management', 'accounting_lite', 'accounting_core'] as $feature) {
            $this->assertTrue($features[$feature] ?? false, "Demo snapshot should enable [{$feature}].");
        }
        $this->assertDemoRoleHasPermissions('demo_cashier', ['pos.view', 'pos.sell', 'cash_shifts.view']);
        $this->assertDemoRoleHasPermissions('demo_inventory_manager', ['inventory.dashboard.view', 'inventory.stock.view', 'purchase_orders.view']);
        $this->assertDemoRoleHasPermissions('demo_accountant', ['expenses.view', 'accounting.core.view', 'accounting.journals.view']);
        $this->assertSame(0, DB::table('business_settings')->where(function ($query) {
            $query->where('type', 'like', '%token%')->orWhere('type', 'like', '%secret%');
        })->whereNotNull('value')->where('value', '!=', '')->count());
    }

    public function test_apply_is_idempotent_and_reset_rebuilds_without_duplication(): void
    {
        $this->bindDatabase('coremarket_showroom_demo');
        $arguments = ['--apply' => true, '--confirm-demo-seed' => true, '--with-samples' => 'standard'];

        $this->artisan('coremarket:seed-demo', $arguments)->assertExitCode(0);
        $first = $this->demoCounts();
        $this->artisan('coremarket:seed-demo', $arguments)->assertExitCode(0);
        $this->assertSame($first, $this->demoCounts());

        $this->artisan('coremarket:seed-demo', array_merge($arguments, ['--reset' => true]))->assertExitCode(0);
        $this->assertSame($first, $this->demoCounts());
    }

    public function test_seeded_data_has_stock_loyalty_cash_and_redemption_integrity(): void
    {
        $this->bindDatabase('coremarket_showroom_demo');
        $this->artisan('coremarket:seed-demo', [
            '--apply' => true,
            '--confirm-demo-seed' => true,
            '--with-samples' => 'standard',
        ])->assertExitCode(0);

        $this->assertSame(0, DB::table('product_stocks')->where('sku', 'like', 'DEMO-%')->where('qty', '<', 0)->count());
        $this->assertSame(0, DB::table('loyalty_accounts')->whereIn('user_id', function ($query) {
            $query->select('id')->from('users')->where('email', 'like', '%@coremarket.demo');
        })->where('points_balance', '<', 0)->count());
        $this->assertGreaterThanOrEqual(3, DB::table('orders')->where('pos_request_key', 'like', 'demo:%')->where('loyalty_points_redeemed', '>', 0)->count());
        $this->assertSame(0, DB::table('orders')->where('pos_request_key', 'like', 'demo:%')->whereNotExists(function ($query) {
            $query->selectRaw('1')->from('cash_movements')
                ->whereColumn('cash_movements.reference_id', 'orders.id')
                ->where('cash_movements.reference_type', Order::class)
                ->where('cash_movements.movement_type', 'sale');
        })->count());
        $this->assertGreaterThan(0, DB::table('loyalty_point_movements')->where('movement_type', 'redeem_restore')->where('idempotency_key', 'like', 'demo:%')->count());
    }

    public function test_service_execution_also_requires_apply_and_confirmation(): void
    {
        $seeder = new CoreMarketDemoSeeder('coremarket_showroom_demo');
        $plan = $seeder->buildPlan([]);

        $this->assertSame([
            'status' => 'refused',
            'records_written' => 0,
            'reset_performed' => false,
            'counts' => [],
        ], $seeder->execute($plan));
    }

    public function test_output_does_not_expose_passwords_or_secrets(): void
    {
        $this->bindDatabase('coremarket_showroom_demo');

        Artisan::call('coremarket:seed-demo');
        $output = Artisan::output();

        $this->assertStringNotContainsString('DB_PASSWORD', $output);
        $this->assertStringNotContainsString('APP_KEY', $output);
        $this->assertStringNotContainsString('DemoAdmin@2026!', $output);
        $this->assertStringNotContainsString('password', strtolower($output));
    }

    public function test_invalid_sample_profile_is_refused(): void
    {
        $this->bindDatabase('coremarket_showroom_demo');

        $this->artisan('coremarket:seed-demo', ['--with-samples' => 'huge'])
            ->expectsOutput('Demo seed refused by the database safety guard.')
            ->assertExitCode(1);
    }

    private function bindDatabase(string $database): void
    {
        $this->app->instance(CoreMarketDemoSeeder::class, new CoreMarketDemoSeeder($database));
    }

    private function databaseCounts(): array
    {
        return [
            'users' => DB::table('users')->count(),
            'products' => DB::table('products')->count(),
            'orders' => DB::table('orders')->count(),
            'loyalty_accounts' => DB::table('loyalty_accounts')->count(),
            'cashboxes' => DB::table('cashboxes')->count(),
        ];
    }

    private function demoCounts(): array
    {
        return [
            'users' => DB::table('users')->where('email', 'like', '%@coremarket.demo')->count(),
            'products' => DB::table('products')->where('barcode', 'like', '629000%')->count(),
            'orders' => DB::table('orders')->where('pos_request_key', 'like', 'demo:%')->count(),
            'cashboxes' => DB::table('cashboxes')->where('code', 'like', 'DEMO-%')->count(),
            'loyalty' => DB::table('loyalty_point_movements')->where('idempotency_key', 'like', 'demo:%')->count(),
            'returns' => DB::table('sales_returns')->where('return_number', 'like', 'DEMO-SR-%')->count(),
            'suppliers' => DB::table('suppliers')->where('email', 'like', '%@coremarket.demo')->count(),
            'expenses' => DB::table('expenses')->where('reference_number', 'like', 'DEMO-EXP-%')->count(),
        ];
    }

    private function baselineHashes(): array
    {
        return [
            'clean' => hash_file('sha256', base_path('database/base/coremarket.sql')),
            'test' => hash_file('sha256', base_path('database/base/coremarket_test.sql')),
        ];
    }

    private function assertDemoRoleHasPermissions(string $roleName, array $permissions): void
    {
        $roleId = DB::table('roles')->where('name', $roleName)->where('guard_name', 'web')->value('id');
        $actual = DB::table('role_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('role_has_permissions.role_id', $roleId)
            ->pluck('permissions.name');

        foreach ($permissions as $permission) {
            $this->assertContains($permission, $actual, "Demo role {$roleName} is missing {$permission}.");
        }
    }
}
