<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CoreMarketStorefrontCleanupCommandTest extends TestCase
{
    public function test_cleanup_dry_run_does_not_write_database(): void
    {
        $original = BusinessSetting::query()->where('type', 'website_name')->whereNull('lang')->value('value');

        $this->artisan('coremarket:clean-storefront-settings', [
            '--dry-run' => true,
        ])
            ->expectsOutput('CoreMarket storefront settings cleanup plan')
            ->expectsOutput('Dry-run complete. No database changes were made.')
            ->assertExitCode(0);

        $this->assertSame(
            $original,
            BusinessSetting::query()->where('type', 'website_name')->whereNull('lang')->value('value')
        );
    }

    public function test_cleanup_apply_without_confirmation_does_not_write_database(): void
    {
        DB::beginTransaction();

        $original = BusinessSetting::query()->where('type', 'show_website_popup')->whereNull('lang')->value('value');

        $this->artisan('coremarket:clean-storefront-settings', [
            '--apply' => true,
        ])
            ->expectsOutput('Apply mode was requested, but the safety requirements were not met.')
            ->assertExitCode(0);

        $this->assertSame(
            $original,
            BusinessSetting::query()->where('type', 'show_website_popup')->whereNull('lang')->value('value')
        );

        DB::rollBack();
    }

    public function test_cleanup_apply_with_confirmation_updates_only_allowed_settings(): void
    {
        DB::beginTransaction();

        DB::table('business_settings')->updateOrInsert(
            ['type' => 'website_name', 'lang' => null],
            ['value' => 'Coin Market']
        );
        DB::table('business_settings')->updateOrInsert(
            ['type' => 'show_website_popup', 'lang' => null],
            ['value' => 'on']
        );
        DB::table('business_settings')->updateOrInsert(
            ['type' => 'footer_title', 'lang' => 'en'],
            ['value' => 'Coin Market']
        );
        DB::table('business_settings')->updateOrInsert(
            ['type' => 'system_logo_white', 'lang' => null],
            ['value' => '8']
        );

        $this->artisan('coremarket:clean-storefront-settings', [
            '--apply' => true,
            '--confirm-storefront-cleanup' => true,
        ])
            ->expectsOutput('Applying allowed storefront business_settings only...')
            ->expectsOutput('Apply complete. Only the allowed storefront business_settings were updated.')
            ->assertExitCode(0);

        $this->assertSame(
            'CoreMarket',
            BusinessSetting::query()->where('type', 'website_name')->whereNull('lang')->value('value')
        );
        $this->assertSame(
            '0',
            (string) BusinessSetting::query()->where('type', 'show_website_popup')->whereNull('lang')->value('value')
        );
        $this->assertSame(
            'CoreMarket',
            BusinessSetting::query()->where('type', 'footer_title')->where('lang', 'en')->value('value')
        );
        $this->assertSame(
            '8',
            BusinessSetting::query()->where('type', 'system_logo_white')->whereNull('lang')->value('value')
        );

        DB::rollBack();
    }

    public function test_cleanup_does_not_touch_products_orders_users_or_uploads(): void
    {
        DB::beginTransaction();

        $before = [
            'products' => DB::table('products')->count(),
            'orders' => DB::table('orders')->count(),
            'users' => DB::table('users')->count(),
            'uploads' => DB::getSchemaBuilder()->hasTable('uploads') ? DB::table('uploads')->count() : null,
        ];

        $this->artisan('coremarket:clean-storefront-settings', [
            '--apply' => true,
            '--confirm-storefront-cleanup' => true,
        ])->assertExitCode(0);

        $after = [
            'products' => DB::table('products')->count(),
            'orders' => DB::table('orders')->count(),
            'users' => DB::table('users')->count(),
            'uploads' => DB::getSchemaBuilder()->hasTable('uploads') ? DB::table('uploads')->count() : null,
        ];

        $this->assertSame($before, $after);

        DB::rollBack();
    }

    public function test_homepage_hides_website_popup_when_setting_is_disabled(): void
    {
        DB::beginTransaction();

        DB::table('business_settings')->updateOrInsert(
            ['type' => 'show_website_popup', 'lang' => null],
            ['value' => '0']
        );
        DB::table('dynamic_popups')->updateOrInsert(
            ['id' => 2],
            [
                'status' => 1,
                'title' => 'Legacy Popup',
                'summary' => 'Legacy summary',
                'banner' => null,
                'btn_link' => '#',
                'btn_text' => 'Open',
                'btn_text_color' => 'white',
                'btn_background_color' => '#000000',
                'show_subscribe_form' => null,
            ]
        );

        Cache::forget('business_settings');

        $this->get('/')
            ->assertOk()
            ->assertDontSee('website-popup removable-session', false);

        DB::rollBack();
    }

    public function test_homepage_hides_cookie_alert_when_setting_is_disabled(): void
    {
        DB::beginTransaction();

        DB::table('business_settings')->updateOrInsert(
            ['type' => 'show_cookies_agreement', 'lang' => null],
            ['value' => '0']
        );

        Cache::forget('business_settings');

        $this->get('/')
            ->assertOk()
            ->assertDontSee('aiz-cookie-alert', false);

        DB::rollBack();
    }

    public function test_cleanup_command_and_docs_do_not_hardcode_petdyzer_strings(): void
    {
        $blockedDomain = 'www.' . 'petdyzer.com';
        $blockedName = 'Pet' . 'dyzer';
        $files = [
            app_path('Console/Commands/CoreMarketCleanStorefrontSettings.php'),
            app_path('Services/CoreMarketStorefrontCleanupService.php'),
            base_path('docs/managed-instance-setup.md'),
            base_path('docs/branding-cleanup.md'),
        ];

        foreach ($files as $file) {
            $contents = file_get_contents($file);

            $this->assertStringNotContainsString($blockedDomain, $contents);
            $this->assertStringNotContainsString($blockedName, $contents);
        }
    }
}
