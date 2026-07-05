<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CoreMarketQaStoreSeedCommandTest extends TestCase
{
    public function test_qa_store_seed_dry_run_does_not_write_database(): void
    {
        $before = [
            'users' => DB::table('users')->count(),
            'categories' => DB::table('categories')->count(),
            'products' => DB::table('products')->count(),
            'product_stocks' => DB::table('product_stocks')->count(),
            'addresses' => DB::table('addresses')->count(),
        ];

        $this->artisan('coremarket:seed-qa-store', [
            '--dry-run' => true,
        ])
            ->expectsOutput('CoreMarket QA store seed plan')
            ->expectsOutput('Dry-run complete. No database changes were made.')
            ->assertExitCode(0);

        $after = [
            'users' => DB::table('users')->count(),
            'categories' => DB::table('categories')->count(),
            'products' => DB::table('products')->count(),
            'product_stocks' => DB::table('product_stocks')->count(),
            'addresses' => DB::table('addresses')->count(),
        ];

        $this->assertSame($before, $after);
    }

    public function test_qa_store_seed_apply_without_confirmation_does_not_write_database(): void
    {
        DB::beginTransaction();

        try {
            $before = DB::table('users')->count();

            $this->artisan('coremarket:seed-qa-store', [
                '--apply' => true,
            ])
                ->expectsOutput('Apply mode was requested, but the safety requirements were not met.')
                ->assertExitCode(0);

            $this->assertSame($before, DB::table('users')->count());
        } finally {
            DB::rollBack();
        }
    }

    public function test_qa_store_seed_apply_is_idempotent(): void
    {
        DB::beginTransaction();

        try {
            Role::query()->firstOrCreate([
                'name' => config('coremarket.access.store_admin_role', 'store_admin'),
                'guard_name' => 'web',
            ]);

            $this->artisan('coremarket:seed-qa-store', [
                '--apply' => true,
                '--confirm-qa-seed' => true,
            ])
                ->expectsOutput('Applying local-only QA store seed...')
                ->expectsOutput('Apply complete. QA local store data is ready.')
                ->assertExitCode(0);

            $firstCounts = [
                'customer_users' => DB::table('users')->where('email', 'qa.customer.coremarket@example.test')->count(),
                'store_admin_users' => DB::table('users')->where('email', 'qa.storeadmin.coremarket@example.test')->count(),
                'qa_categories' => DB::table('categories')->where('slug', 'qa-coremarket-category')->count(),
                'qa_products' => DB::table('products')->where('slug', 'qa-coremarket-sample-product')->count(),
                'qa_product_stocks' => DB::table('product_stocks')->where('sku', 'QA-COREMARKET-SKU')->count(),
            ];

            $this->artisan('coremarket:seed-qa-store', [
                '--apply' => true,
                '--confirm-qa-seed' => true,
            ])->assertExitCode(0);

            $secondCounts = [
                'customer_users' => DB::table('users')->where('email', 'qa.customer.coremarket@example.test')->count(),
                'store_admin_users' => DB::table('users')->where('email', 'qa.storeadmin.coremarket@example.test')->count(),
                'qa_categories' => DB::table('categories')->where('slug', 'qa-coremarket-category')->count(),
                'qa_products' => DB::table('products')->where('slug', 'qa-coremarket-sample-product')->count(),
                'qa_product_stocks' => DB::table('product_stocks')->where('sku', 'QA-COREMARKET-SKU')->count(),
            ];

            $this->assertSame([
                'customer_users' => 1,
                'store_admin_users' => 1,
                'qa_categories' => 1,
                'qa_products' => 1,
                'qa_product_stocks' => 1,
            ], $firstCounts);

            $this->assertSame($firstCounts, $secondCounts);
            $this->assertSame('1', (string) DB::table('business_settings')->where('type', 'cash_payment')->whereNull('lang')->value('value'));
            $this->assertSame('0', (string) DB::table('business_settings')->where('type', 'wallet_system')->whereNull('lang')->value('value'));
            $this->assertSame('0', (string) DB::table('business_settings')->where('type', 'show_website_popup')->whereNull('lang')->value('value'));
        } finally {
            DB::rollBack();
        }
    }

    public function test_qa_seed_command_and_docs_do_not_hardcode_petdyzer_strings(): void
    {
        $blockedDomain = 'www.' . 'petdyzer.com';
        $blockedName = 'Pet' . 'dyzer';
        $files = [
            app_path('Console/Commands/CoreMarketSeedQaStore.php'),
            app_path('Services/CoreMarketQaStoreSeedService.php'),
            base_path('docs/qa-store-seed.md'),
        ];

        foreach ($files as $file) {
            $contents = file_get_contents($file);

            $this->assertStringNotContainsString($blockedDomain, $contents);
            $this->assertStringNotContainsString($blockedName, $contents);
        }
    }
}
