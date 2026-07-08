<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\Shop;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CoreMarketSetupInstanceCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['business_settings', 'currencies', 'languages', 'users'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->markTestSkipped("The {$table} table is not available in the testing database.");
            }
        }
    }

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

    public function test_dry_run_outputs_runtime_plan_and_store_mode_summary(): void
    {
        $this->artisan('coremarket:setup-instance', [
            'instance_id' => 'client-store',
            '--dry-run' => true,
            '--store-name' => 'Client Store',
            '--domain' => 'example-store.com',
            '--plan' => 'starter',
            '--store-mode' => 'single_store',
            '--admin-email' => 'owner@example-store.com',
            '--support-email' => 'support@example-store.com',
            '--currency' => 'USD',
            '--language' => 'en',
        ])
            ->expectsOutput('CoreMarket managed instance setup plan')
            ->expectsOutput('Runtime access preview')
            ->assertExitCode(0);
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
            ->expectsOutput('Applying allowed business_settings and optional Store Admin changes...')
            ->expectsOutput('Apply complete. Only the allowed business_settings and explicit Store Admin changes were updated.')
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
            '0',
            (string) BusinessSetting::query()->where('type', 'show_website_popup')->whereNull('lang')->value('value')
        );
        $this->assertSame(
            'Asia/Beirut',
            BusinessSetting::query()->where('type', 'timezone')->whereNull('lang')->value('value')
        );

        DB::rollBack();
    }

    public function test_marketplace_mode_only_enables_vendor_activation_when_plan_allows_it(): void
    {
        DB::beginTransaction();

        $this->artisan('coremarket:setup-instance', [
            'instance_id' => 'client-store',
            '--apply' => true,
            '--confirm-instance-setup' => true,
            '--store-name' => 'Client Store',
            '--domain' => 'example-store.com',
            '--admin-email' => 'owner@example-store.com',
            '--plan' => 'starter',
            '--store-mode' => 'marketplace',
            '--currency' => 'USD',
            '--language' => 'en',
        ])->assertExitCode(0);

        $this->assertSame(
            '0',
            (string) BusinessSetting::query()->where('type', 'vendor_system_activation')->whereNull('lang')->value('value')
        );

        DB::rollBack();
    }

    public function test_apply_updates_existing_shop_branding_preview_fields(): void
    {
        DB::beginTransaction();

        DB::table('shops')->where('id', 1)->update([
            'name' => 'Legacy Shop',
            'slug' => 'legacy-shop',
            'phone' => '111',
            'address' => 'Legacy Address',
            'meta_title' => 'Legacy Shop',
            'meta_description' => 'Legacy Description',
        ]);

        $this->artisan('coremarket:setup-instance', [
            'instance_id' => 'client-store',
            '--apply' => true,
            '--confirm-instance-setup' => true,
            '--store-name' => 'Client Store',
            '--domain' => 'example-store.com',
            '--admin-email' => 'owner@example-store.com',
            '--meta-description' => 'Client store description',
            '--contact-phone' => '+10000000000',
            '--contact-address' => 'Managed Address',
        ])
            ->expectsOutput('Shop branding apply result')
            ->assertExitCode(0);

        $shop = Shop::query()->find(1);

        $this->assertSame('Client Store', $shop->name);
        $this->assertSame('example-store-com', $shop->slug);
        $this->assertSame('+10000000000', $shop->phone);
        $this->assertSame('Managed Address', $shop->address);
        $this->assertSame('Client Store', $shop->meta_title);
        $this->assertSame('Client store description', $shop->meta_description);

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
            '--currency' => 'USD',
            '--language' => 'en',
        ])
            ->expectsOutput('Apply mode was requested, but the safety requirements were not met.')
            ->assertExitCode(0);

        $this->assertSame($before, DB::table('users')->count());

        DB::rollBack();
    }

    public function test_apply_can_create_store_admin_when_role_exists_and_password_is_provided(): void
    {
        DB::beginTransaction();

        $role = DB::table('roles')->where('name', config('coremarket.access.store_admin_role', 'store_admin'))->first();
        if (! $role) {
            $this->markTestSkipped('store_admin role is missing in the current database.');
        }

        $email = 'managed.instance.storeadmin@example.test';
        DB::table('users')->where('email', $email)->delete();

        $before = DB::table('users')->count();

        $this->artisan('coremarket:setup-instance', [
            'instance_id' => 'client-store',
            '--apply' => true,
            '--confirm-instance-setup' => true,
            '--create-store-admin' => true,
            '--store-name' => 'Client Store',
            '--domain' => 'example-store.com',
            '--admin-name' => 'Store Manager',
            '--admin-email' => $email,
            '--store-admin-password' => 'Temporary123!',
            '--currency' => 'USD',
            '--language' => 'en',
        ])->assertExitCode(0);

        $this->assertSame($before + 1, DB::table('users')->count());
        $this->assertSame(1, DB::table('users')->where('email', $email)->where('user_type', 'staff')->count());

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
