<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\Shop;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CoreMarketCleanBaselineCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['business_settings', 'shops'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->markTestSkipped("The {$table} table is not available in the testing database.");
            }
        }
    }

    public function test_clean_baseline_dry_run_does_not_write_database(): void
    {
        $before = [
            'website_name' => BusinessSetting::query()->where('type', 'website_name')->whereNull('lang')->value('value'),
            'shop_name' => optional(Shop::query()->first())->name,
        ];

        $this->artisan('coremarket:clean-baseline', [
            '--dry-run' => true,
        ])
            ->expectsOutput('CoreMarket clean baseline plan')
            ->expectsOutput('Dry-run complete. No database changes were made.')
            ->assertExitCode(0);

        $after = [
            'website_name' => BusinessSetting::query()->where('type', 'website_name')->whereNull('lang')->value('value'),
            'shop_name' => optional(Shop::query()->first())->name,
        ];

        $this->assertSame($before, $after);
    }

    public function test_clean_baseline_apply_requires_confirmation(): void
    {
        DB::beginTransaction();

        $original = optional(Shop::query()->first())->name;

        $this->artisan('coremarket:clean-baseline', [
            '--apply' => true,
        ])
            ->expectsOutput('Apply mode was requested, but the safety requirements were not met.')
            ->assertExitCode(0);

        $this->assertSame($original, optional(Shop::query()->first())->name);

        DB::rollBack();
    }

    public function test_clean_baseline_apply_neutralizes_shop_branding_safely(): void
    {
        DB::beginTransaction();

        DB::table('business_settings')->updateOrInsert(
            ['type' => 'website_name', 'lang' => null],
            ['value' => 'Syrian Souq']
        );

        DB::table('shops')->where('id', 1)->update([
            'name' => 'Syrian Souq',
            'slug' => 'Syrian-Souq-1',
            'phone' => '00963986223268',
            'address' => 'Syria, Homs',
            'meta_title' => 'Syrian Souq',
            'meta_description' => 'Syrian Souq the first online shop in syria',
            'facebook' => 'www.facebook.com',
            'twitter' => 'www.twitter.com',
            'youtube' => 'www.youtube.com',
            'google' => 'www.google.com',
        ]);

        $beforeCounts = [
            'products' => DB::table('products')->count(),
            'orders' => DB::table('orders')->count(),
            'uploads' => DB::table('uploads')->count(),
        ];

        $this->artisan('coremarket:clean-baseline', [
            '--apply' => true,
            '--confirm-clean-baseline' => true,
        ])
            ->expectsOutput('Applying clean baseline business_settings and safe shop branding fields...')
            ->expectsOutput('Shop branding apply result')
            ->expectsOutput('Apply complete. Only the allowed baseline business_settings and safe shop branding fields were updated.')
            ->assertExitCode(0);

        $shop = Shop::query()->find(1);

        $this->assertSame('CoreMarket Store', BusinessSetting::query()->where('type', 'website_name')->whereNull('lang')->value('value'));
        $this->assertSame('CoreMarket Store', $shop->name);
        $this->assertSame('coremarket-store', $shop->slug);
        $this->assertSame('', (string) $shop->phone);
        $this->assertSame('', (string) $shop->address);
        $this->assertSame('CoreMarket Store', $shop->meta_title);
        $this->assertSame('Managed ecommerce store powered by CoreMarket', $shop->meta_description);
        $this->assertSame('', (string) $shop->facebook);
        $this->assertSame('', (string) $shop->twitter);
        $this->assertSame('', (string) $shop->youtube);
        $this->assertSame('', (string) $shop->google);

        $afterCounts = [
            'products' => DB::table('products')->count(),
            'orders' => DB::table('orders')->count(),
            'uploads' => DB::table('uploads')->count(),
        ];

        $this->assertSame($beforeCounts, $afterCounts);

        DB::rollBack();
    }
}
