<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminRuntimeNavigationTest extends TestCase
{
    public function test_starter_single_store_sidebar_hides_marketplace_and_owner_only_sections_for_store_admin(): void
    {
        DB::beginTransaction();

        try {
            $this->seedBusinessSetting('vendor_system_activation', 1);
            $this->seedBusinessSetting('product_query_activation', 1);
            $this->seedBusinessSetting('coupon_system', 1);

            config()->set('coremarket.runtime.applied_plan_code', 'starter');
            config()->set('coremarket.runtime.store_mode', 'single_store');

            $user = $this->makeUserWithRoleAndPermissions(
                'Store Admin QA',
                'storeadmin.nav@example.test',
                'staff',
                config('coremarket.access.store_admin_role', 'store_admin'),
                $this->navigationPermissions()
            );

            $this->actingAs($user);

            $html = view('backend.inc.admin_sidenav')->render();

            $this->assertStringContainsString('Products', $html);
            $this->assertStringContainsString('Customers', $html);
            $this->assertStringContainsString('Website Setup', $html);
            $this->assertStringNotContainsString('Seller Product', $html);
            $this->assertStringNotContainsString('Seller Orders', $html);
            $this->assertStringNotContainsString('Seller Verification Form', $html);
            $this->assertStringNotContainsString('POS System', $html);
            $this->assertStringNotContainsString('Product Queries', $html);
            $this->assertStringNotContainsString('Notification Types', $html);
            $this->assertStringNotContainsString('Uploaded Files', $html);
            $this->assertStringNotContainsString('Features activation', $html);
            $this->assertStringNotContainsString('Payment Methods', $html);
            $this->assertStringNotContainsString('Server status', $html);
            $this->assertStringNotContainsString('Appearance', $html);
            $this->assertStringNotContainsString('Authentication Layout & Settings', $html);
            $this->assertStringNotContainsString('Select Homepage', $html);
        } finally {
            DB::rollBack();
            Cache::forget('business_settings');
        }
    }

    public function test_marketplace_owner_sidebar_shows_sellers_and_owner_sections_when_plan_allows_them(): void
    {
        DB::beginTransaction();

        try {
            $this->seedBusinessSetting('vendor_system_activation', 1);

            config()->set('coremarket.runtime.applied_plan_code', 'marketplace');
            config()->set('coremarket.runtime.store_mode', 'marketplace');

            $user = $this->makeUserWithRoleAndPermissions(
                'Owner QA',
                'owner.nav@example.test',
                'admin',
                'Runtime Navigation Owner',
                $this->navigationPermissions()
            );

            $this->actingAs($user);

            $html = view('backend.inc.admin_sidenav')->render();

            $this->assertStringContainsString('Sellers', $html);
            $this->assertStringContainsString('Setup & Configurations', $html);
            $this->assertStringContainsString('System', $html);
            $this->assertStringContainsString('Uploaded Files', $html);
            $this->assertStringContainsString('Staffs', $html);
        } finally {
            DB::rollBack();
            Cache::forget('business_settings');
        }
    }

    public function test_disabled_runtime_feature_routes_return_not_found_for_admin_access(): void
    {
        DB::beginTransaction();

        try {
            config()->set('coremarket.runtime.applied_plan_code', 'starter');
            config()->set('coremarket.runtime.store_mode', 'single_store');

            $user = $this->makeUserWithRoleAndPermissions(
                'Owner QA',
                'owner.routes@example.test',
                'admin',
                'Runtime Route Owner',
                $this->navigationPermissions()
            );

            $this->actingAs($user)->get(route('sellers.index'))->assertNotFound();
            $this->actingAs($user)->get(route('notification.settings'))->assertNotFound();
            $this->actingAs($user)->get(route('product_query.index'))->assertNotFound();
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

    private function seedBusinessSetting(string $type, $value): void
    {
        $setting = BusinessSetting::query()->firstOrNew(['type' => $type]);
        $setting->forceFill([
            'type' => $type,
            'value' => $value,
        ])->save();

        Cache::forget('business_settings');
    }

    private function navigationPermissions(): array
    {
        return [
            'view_all_orders',
            'view_all_customers',
            'add_new_product',
            'show_all_products',
            'show_in_house_products',
            'show_seller_products',
            'view_all_seller',
            'view_seller_orders',
            'view_blogs',
            'view_blog_categories',
            'view_all_flash_deals',
            'view_all_dynamic_popups',
            'send_newsletter',
            'notification_settings',
            'view_all_notification_types',
            'send_custom_notification',
            'view_custom_notification_history',
            'view_all_subscribers',
            'view_all_coupons',
            'view_all_support_tickets',
            'view_all_product_conversations',
            'view_all_product_queries',
            'earning_report',
            'in_house_product_sale_report',
            'seller_products_sale_report',
            'products_stock_report',
            'view_inhouse_orders',
            'header_setup',
            'footer_setup',
            'view_all_website_pages',
            'website_appearance',
            'authentication_layout_settings',
            'select_homepage',
            'edit_website_page',
            'features_activation',
            'system_update',
            'server_status',
            'view_all_staffs',
            'view_staff_roles',
            'manage_addons',
            'uploaded_files',
        ];
    }
}
