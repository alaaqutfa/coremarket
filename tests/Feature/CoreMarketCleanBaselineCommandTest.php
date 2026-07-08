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

        foreach (['business_settings', 'shops', 'translations', 'page_translations', 'messages'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->markTestSkipped("The {$table} table is not available in the testing database.");
            }
        }
    }

    public function test_clean_baseline_dry_run_does_not_write_database(): void
    {
        DB::beginTransaction();

        DB::table('translations')->updateOrInsert(
            ['id' => 5079],
            [
                'lang' => 'en',
                'lang_key' => 'dry_run_brand_message',
                'lang_value' => 'Dry-run Syrian Souq check for :name',
            ]
        );

        $before = [
            'website_name' => BusinessSetting::query()->where('type', 'website_name')->whereNull('lang')->value('value'),
            'shop_name' => optional(Shop::query()->first())->name,
            'translation_value' => DB::table('translations')->where('id', 5079)->value('lang_value'),
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
            'translation_value' => DB::table('translations')->where('id', 5079)->value('lang_value'),
        ];

        $this->assertSame($before, $after);

        DB::rollBack();
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

        $translationsBeforeCount = DB::table('translations')->count();
        $pageTranslationsBeforeCount = DB::table('page_translations')->count();
        $expectedTranslationCountDelta = 0;

        foreach ([78, 79, 1741] as $translationId) {
            if (! DB::table('translations')->where('id', $translationId)->exists()) {
                $expectedTranslationCountDelta++;
            }
        }

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

        DB::table('translations')->updateOrInsert(
            ['id' => 78],
            [
                'lang' => 'en',
                'lang_key' => 'inhouse_product',
                'lang_value' => 'Syrian Souq products',
            ]
        );

        DB::table('translations')->updateOrInsert(
            ['id' => 79],
            [
                'lang' => 'en',
                'lang_key' => 'welcome_brand_message',
                'lang_value' => 'Welcome to Syrian Souq, :name!',
            ]
        );

        DB::table('translations')->updateOrInsert(
            ['id' => 1741],
            [
                'lang' => 'sy',
                'lang_key' => 'inhouse_product',
                'lang_value' => 'منتجات سوق سوريا',
            ]
        );

        DB::table('page_translations')->updateOrInsert(
            ['id' => 2],
            [
                'lang' => 'en',
                'title' => 'Seller Policy | Syrian Souq - The First Online Marketplace',
                'content' => 'Seller policy content for Syrian Souq merchants.',
            ]
        );

        DB::table('messages')->updateOrInsert(
            ['id' => 1],
            [
                'conversation_id' => 1,
                'user_id' => 1,
                'message' => 'https://syriansouq.com/product/test',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

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
            ->expectsOutput('Apply complete. Only the allowed baseline business_settings and safe public metadata fields were updated.')
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
        $this->assertSame('CoreMarket Store products', DB::table('translations')->where('id', 78)->value('lang_value'));
        $this->assertSame('Welcome to CoreMarket Store, :name!', DB::table('translations')->where('id', 79)->value('lang_value'));
        $this->assertSame('منتجات CoreMarket Store', DB::table('translations')->where('id', 1741)->value('lang_value'));
        $this->assertSame('CoreMarket Store', DB::table('page_translations')->where('id', 2)->value('title'));
        $this->assertSame('https://example.com/product/sample', DB::table('messages')->where('id', 1)->value('message'));
        $this->assertSame('welcome_brand_message', DB::table('translations')->where('id', 79)->value('lang_key'));
        $this->assertSame('en', DB::table('translations')->where('id', 79)->value('lang'));
        $this->assertSame($translationsBeforeCount + $expectedTranslationCountDelta, DB::table('translations')->count());
        $this->assertSame($pageTranslationsBeforeCount, DB::table('page_translations')->count());

        $afterCounts = [
            'products' => DB::table('products')->count(),
            'orders' => DB::table('orders')->count(),
            'uploads' => DB::table('uploads')->count(),
        ];

        $this->assertSame($beforeCounts, $afterCounts);

        DB::rollBack();
    }
}
