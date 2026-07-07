<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\Currency;
use App\Models\Language;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminLocalizationControlsTest extends TestCase
{
    public function test_store_admin_can_access_limited_translations_page_when_feature_enabled(): void
    {
        DB::beginTransaction();

        try {
            $language = $this->seedLanguage([
                'name' => 'Arabic QA',
                'code' => 'ar-qa',
                'app_lang_code' => 'ar',
                'status' => 1,
            ]);

            config()->set('coremarket.features.translations_limited', true);

            $user = $this->makeUserWithRoleAndPermissions(
                'Store Admin Localization',
                'storeadmin.localization@example.test',
                'staff',
                config('coremarket.access.store_admin_role', 'store_admin'),
                ['header_setup']
            );

            $this->actingAs($user)
                ->get(route('website.translations.index'))
                ->assertOk()
                ->assertSee('Translations')
                ->assertSee('Arabic QA');

            $this->actingAs($user)
                ->get(route('website.translations.show', $language->id))
                ->assertOk()
                ->assertSee('Copy Translations');
        } finally {
            DB::rollBack();
            Cache::forget('business_settings');
        }
    }

    public function test_store_admin_cannot_access_unsafe_language_actions(): void
    {
        DB::beginTransaction();

        try {
            $language = $this->seedLanguage([
                'name' => 'Unsafe QA',
                'code' => 'unsafe-qa',
                'app_lang_code' => 'uq',
                'status' => 1,
            ]);

            $user = $this->makeUserWithRoleAndPermissions(
                'Store Admin Localization Unsafe',
                'storeadmin.localization.unsafe@example.test',
                'staff',
                config('coremarket.access.store_admin_role', 'store_admin'),
                ['header_setup']
            );

            $this->actingAs($user)->post(route('languages.update-status'), [
                'id' => $language->id,
                'status' => 0,
            ])->assertForbidden();

            $this->actingAs($user)->post(route('currency.store'), [
                'name' => 'Unsafe Currency',
                'symbol' => 'UQ',
                'code' => 'UQC',
                'exchange_rate' => 1.5,
            ])->assertForbidden();
        } finally {
            DB::rollBack();
        }
    }

    public function test_store_admin_can_access_limited_currency_rates_page_when_feature_enabled(): void
    {
        DB::beginTransaction();

        try {
            $currency = $this->seedCurrency([
                'name' => 'QA Dollar',
                'symbol' => 'Q$',
                'code' => 'QAD',
                'exchange_rate' => 1.25,
                'status' => 1,
            ]);

            config()->set('coremarket.features.currencies_limited', true);

            $user = $this->makeUserWithRoleAndPermissions(
                'Store Admin Currency',
                'storeadmin.currency@example.test',
                'staff',
                config('coremarket.access.store_admin_role', 'store_admin'),
                ['footer_setup']
            );

            $this->actingAs($user)
                ->get(route('website.currency-rates.index'))
                ->assertOk()
                ->assertSee('Currency Rates')
                ->assertSee('QA Dollar')
                ->assertSee('QAD');

            $this->actingAs($user)
                ->post(route('website.currency-rates.update'), [
                    'id' => $currency->id,
                    'exchange_rate' => 2.5,
                ])
                ->assertRedirect(route('website.currency-rates.index'));

            $this->assertSame('2.5', (string) Currency::query()->findOrFail($currency->id)->exchange_rate);
        } finally {
            DB::rollBack();
            Cache::forget('business_settings');
        }
    }

    public function test_sidebar_hides_limited_localization_links_when_features_are_disabled(): void
    {
        DB::beginTransaction();

        try {
            config()->set('coremarket.features.translations_limited', false);
            config()->set('coremarket.features.currencies_limited', false);

            $user = $this->makeUserWithRoleAndPermissions(
                'Store Admin Sidebar Hidden',
                'storeadmin.sidebar.hidden@example.test',
                'staff',
                config('coremarket.access.store_admin_role', 'store_admin'),
                ['header_setup', 'footer_setup', 'view_all_website_pages']
            );

            $this->actingAs($user);

            $html = view('backend.inc.admin_sidenav')->render();

            $this->assertStringNotContainsString('Translations', $html);
            $this->assertStringNotContainsString('Currency Rates', $html);
        } finally {
            DB::rollBack();
        }
    }

    public function test_business_plan_can_show_limited_localization_links_when_features_are_enabled(): void
    {
        DB::beginTransaction();

        try {
            config()->set('coremarket.runtime.applied_plan_code', 'business');
            config()->set('coremarket.runtime.store_mode', 'single_store');
            config()->set('coremarket.features.translations_limited', true);
            config()->set('coremarket.features.currencies_limited', true);

            $user = $this->makeUserWithRoleAndPermissions(
                'Store Admin Sidebar Visible',
                'storeadmin.sidebar.visible@example.test',
                'staff',
                config('coremarket.access.store_admin_role', 'store_admin'),
                ['header_setup', 'footer_setup', 'view_all_website_pages']
            );

            $this->actingAs($user);

            $html = view('backend.inc.admin_sidenav')->render();

            $this->assertStringContainsString('Translations', $html);
            $this->assertStringContainsString('Currency Rates', $html);
        } finally {
            DB::rollBack();
        }
    }

    private function makeUserWithRoleAndPermissions(
        string $name,
        string $email,
        string $userType,
        string $roleName,
        array $permissions
    ): User {
        $this->seedPermissions($permissions, $roleName);

        $user = new User();
        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'password' => bcrypt('Temporary123!'),
            'user_type' => $userType,
            'email_verified_at' => now(),
        ])->save();

        $user->assignRole($roleName);

        return $user;
    }

    private function seedPermissions(array $permissions, string $roleName): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::query()->firstOrCreate([
            'name' => $roleName,
            'guard_name' => 'web',
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web',
            ]);

            $role->givePermissionTo($permission);
        }
    }

    private function seedLanguage(array $attributes): Language
    {
        $language = new Language();
        $language->forceFill(array_merge([
            'rtl' => 0,
        ], $attributes))->save();

        return $language;
    }

    private function seedCurrency(array $attributes): Currency
    {
        $currency = new Currency();
        $currency->forceFill($attributes)->save();

        return $currency;
    }
}
