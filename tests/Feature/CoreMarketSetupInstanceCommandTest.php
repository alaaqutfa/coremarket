<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CoreMarketSetupInstanceCommandTest extends TestCase
{
    public function test_command_dry_run_does_not_write_to_database(): void
    {
        $before = BusinessSetting::count();

        $this->artisan('coremarket:setup-instance', [
            'instance_id' => 'client-store',
            '--dry-run' => true,
            '--store-name' => 'Client Store',
            '--domain' => 'example-store.com',
        ])
            ->expectsOutput('CoreMarket managed instance setup plan')
            ->assertExitCode(0);

        $this->assertSame($before, BusinessSetting::count());
    }

    public function test_apply_without_confirmation_does_not_write_business_settings(): void
    {
        DB::beginTransaction();

        $original = BusinessSetting::query()->where('type', 'website_name')->whereNull('lang')->value('value');

        $this->artisan('coremarket:setup-instance', [
            'instance_id' => 'client-store',
            '--apply' => true,
            '--store-name' => 'Client Store',
            '--domain' => 'example-store.com',
            '--admin-email' => 'owner@example-store.com',
        ])
            ->expectsOutput('Apply mode was requested, but the safety requirements were not met.')
            ->assertExitCode(0);

        $this->assertSame(
            $original,
            BusinessSetting::query()->where('type', 'website_name')->whereNull('lang')->value('value')
        );

        DB::rollBack();
    }

    public function test_apply_with_confirmation_updates_allowed_business_settings_only(): void
    {
        DB::beginTransaction();

        $websiteName = BusinessSetting::query()->firstOrNew(['type' => 'website_name', 'lang' => null]);
        $websiteName->value = 'Old Store';
        $websiteName->save();

        $vendorActivation = BusinessSetting::query()->firstOrNew(['type' => 'vendor_system_activation', 'lang' => null]);
        $vendorActivation->value = '1';
        $vendorActivation->save();

        $this->artisan('coremarket:setup-instance', [
            'instance_id' => 'client-store',
            '--apply' => true,
            '--confirm-instance-setup' => true,
            '--store-name' => 'Client Store',
            '--domain' => 'example-store.com',
            '--admin-email' => 'owner@example-store.com',
            '--site-motto' => 'Healthy pets, happy homes',
            '--meta-description' => 'General managed store setup preview.',
            '--contact-phone' => '+10000000000',
            '--footer-text' => 'Client Store',
            '--timezone' => 'Asia/Beirut',
        ])
            ->expectsOutput('Applying allowed business_settings only...')
            ->expectsOutput('Apply complete. Only the allowed business_settings were updated.')
            ->assertExitCode(0);

        $this->assertSame(
            'Client Store',
            BusinessSetting::query()->where('type', 'website_name')->whereNull('lang')->value('value')
        );
        $this->assertSame(
            '0',
            (string) BusinessSetting::query()->where('type', 'vendor_system_activation')->whereNull('lang')->value('value')
        );
        $this->assertSame(
            'Asia/Beirut',
            BusinessSetting::query()->where('type', 'timezone')->whereNull('lang')->value('value')
        );

        DB::rollBack();
    }

    public function test_apply_does_not_touch_unrelated_business_settings(): void
    {
        DB::beginTransaction();

        $setting = BusinessSetting::query()->firstOrNew(['type' => 'system_logo_white', 'lang' => null]);
        $setting->value = '999';
        $setting->save();

        $this->artisan('coremarket:setup-instance', [
            'instance_id' => 'client-store',
            '--apply' => true,
            '--confirm-instance-setup' => true,
            '--store-name' => 'Client Store',
            '--domain' => 'example-store.com',
            '--admin-email' => 'owner@example-store.com',
        ])->assertExitCode(0);

        $this->assertSame(
            '999',
            BusinessSetting::query()->where('type', 'system_logo_white')->whereNull('lang')->value('value')
        );

        DB::rollBack();
    }

    public function test_apply_does_not_create_admin_unless_flag_is_passed(): void
    {
        DB::beginTransaction();

        $before = DB::table('users')->count();

        $this->artisan('coremarket:setup-instance', [
            'instance_id' => 'client-store',
            '--apply' => true,
            '--confirm-instance-setup' => true,
            '--store-name' => 'Client Store',
            '--domain' => 'example-store.com',
            '--admin-email' => 'owner@example-store.com',
        ])->assertExitCode(0);

        $this->assertSame($before, DB::table('users')->count());

        DB::rollBack();
    }

    public function test_store_admin_creation_preview_guard_works(): void
    {
        DB::beginTransaction();

        $before = DB::table('users')->count();

        $this->artisan('coremarket:setup-instance', [
            'instance_id' => 'client-store',
            '--apply' => true,
            '--confirm-instance-setup' => true,
            '--create-store-admin' => true,
            '--store-name' => 'Client Store',
            '--domain' => 'example-store.com',
            '--admin-name' => 'Store Manager',
            '--admin-email' => 'owner@example-store.com',
        ])
            ->expectsOutput('Apply mode was requested, but the safety requirements were not met.')
            ->assertExitCode(0);

        $this->assertSame($before, DB::table('users')->count());

        DB::rollBack();
    }

    public function test_env_example_exists_and_does_not_include_petdyzer(): void
    {
        $path = base_path('.env.example');

        $this->assertFileExists($path);

        $contents = file_get_contents($path);

        $this->assertStringNotContainsString('Petdyzer', $contents);
        $this->assertStringNotContainsString('www.petdyzer.com', $contents);
        $this->assertStringContainsString('COREMARKET_INSTANCE_ID=', $contents);
    }

    public function test_command_docs_and_tests_do_not_hardcode_petdyzer_strings(): void
    {
        $blockedDomain = 'www.' . 'petdyzer.com';
        $blockedName = 'Pet' . 'dyzer';
        $files = [
            app_path('Console/Commands/CoreMarketSetupInstance.php'),
            app_path('Services/CoreMarketInstanceSetupService.php'),
            base_path('docs/managed-instance-setup.md'),
        ];

        foreach ($files as $file) {
            $contents = file_get_contents($file);

            $this->assertStringNotContainsString($blockedDomain, $contents);
            $this->assertStringNotContainsString($blockedName, $contents);
        }
    }
}
