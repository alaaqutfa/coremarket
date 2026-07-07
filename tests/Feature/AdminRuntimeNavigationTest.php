<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
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
            $this->assertStringContainsString('My Subscription', $html);
            $this->assertStringContainsString('Addon Requests', $html);
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
            $this->assertStringContainsString('My Subscription', $html);
            $this->assertStringContainsString('Addon Requests', $html);
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

    public function test_dashboard_hides_seller_components_for_starter_single_store_even_when_vendor_setting_exists(): void
    {
        DB::beginTransaction();

        try {
            $this->seedBusinessSetting('vendor_system_activation', 1);

            config()->set('coremarket.runtime.applied_plan_code', 'starter');
            config()->set('coremarket.runtime.store_mode', 'single_store');

            $user = $this->makeUserWithRoleAndPermissions(
                'Owner Dashboard QA',
                'owner.dashboard@example.test',
                'admin',
                'Runtime Dashboard Owner',
                array_merge($this->navigationPermissions(), ['admin_dashboard'])
            );

            $this->actingAs($user);

            $html = view('backend.dashboard', $this->dashboardViewData())->render();

            $this->assertStringContainsString('Total Products', $html);
            $this->assertStringNotContainsString('Sellers Products', $html);
            $this->assertStringNotContainsString('Sellers Sales', $html);
            $this->assertStringNotContainsString('Total Sellers', $html);
            $this->assertStringNotContainsString('Top Seller & Products', $html);
            $this->assertStringNotContainsString('Activate Vendor System', $html);
        } finally {
            DB::rollBack();
            Cache::forget('business_settings');
        }
    }

    public function test_product_index_hides_seller_controls_and_shows_usage_card_for_store_admin_in_single_store_mode(): void
    {
        DB::beginTransaction();

        try {
            $this->seedBusinessSetting('vendor_system_activation', 1);

            config()->set('coremarket.runtime.applied_plan_code', 'starter');
            config()->set('coremarket.runtime.store_mode', 'single_store');

            $user = $this->makeUserWithRoleAndPermissions(
                'Store Admin Product QA',
                'storeadmin.products@example.test',
                'staff',
                config('coremarket.access.store_admin_role', 'store_admin'),
                ['add_new_product', 'show_all_products']
            );

            $this->actingAs($user);

            $html = view('backend.product.products.index', [
                'type' => 'All',
                'seller_id' => null,
                'products' => $this->emptyPaginator(),
            ])->render();

            $this->assertStringContainsString('Products usage', $html);
            $this->assertStringNotContainsString('All Sellers', $html);
            $this->assertStringNotContainsString('Added By', $html);
        } finally {
            DB::rollBack();
            Cache::forget('business_settings');
        }
    }

    public function test_orders_index_hides_seller_columns_when_sellers_are_disabled(): void
    {
        DB::beginTransaction();

        try {
            $this->seedBusinessSetting('vendor_system_activation', 1);
            $this->seedBusinessSetting('vendor_commission_activation', 1);

            config()->set('coremarket.runtime.applied_plan_code', 'starter');
            config()->set('coremarket.runtime.store_mode', 'single_store');

            $user = $this->makeUserWithRoleAndPermissions(
                'Owner Orders QA',
                'owner.orders@example.test',
                'admin',
                'Runtime Orders Owner',
                ['view_order_details']
            );

            $this->actingAs($user);

            $html = view('backend.sales.index', [
                'orders' => $this->emptyPaginator(),
                'delivery_status' => null,
                'payment_status' => null,
                'date' => null,
                'sort_search' => null,
            ])->render();

            $this->assertStringContainsString('Order Code', $html);
            $this->assertStringContainsString('Payment method', $html);
            $this->assertStringNotContainsString('<th data-breakpoints="md">Seller</th>', $html);
            $this->assertStringNotContainsString('due_to_seller', $html);
            $this->assertStringNotContainsString('commission', $html);
        } finally {
            DB::rollBack();
            Cache::forget('business_settings');
        }
    }

    public function test_business_single_store_blocks_seller_and_wallet_reports_even_when_advanced_reports_are_enabled(): void
    {
        DB::beginTransaction();

        try {
            $this->seedBusinessSetting('vendor_system_activation', 1);
            $this->seedBusinessSetting('wallet_system', 1);

            config()->set('coremarket.runtime.applied_plan_code', 'business');
            config()->set('coremarket.runtime.store_mode', 'single_store');

            $user = $this->makeUserWithRoleAndPermissions(
                'Owner Reports QA',
                'owner.reports@example.test',
                'admin',
                'Runtime Reports Owner',
                ['seller_products_sale_report', 'commission_history_report', 'wallet_transaction_report']
            );

            $this->actingAs($user)->get(route('seller_sale_report.index'))->assertNotFound();
            $this->actingAs($user)->get(route('commission-log.index'))->assertNotFound();
            $this->actingAs($user)->get(route('wallet-history.index'))->assertNotFound();
        } finally {
            DB::rollBack();
            Cache::forget('business_settings');
        }
    }

    public function test_earning_report_hides_seller_and_delivery_series_when_runtime_features_are_disabled(): void
    {
        DB::beginTransaction();

        try {
            $this->seedBusinessSetting('vendor_system_activation', 1);
            $this->seedBusinessSetting('wallet_system', 1);

            config()->set('coremarket.runtime.applied_plan_code', 'business');
            config()->set('coremarket.runtime.store_mode', 'single_store');

            $user = $this->makeUserWithRoleAndPermissions(
                'Owner Earning QA',
                'owner.earning@example.test',
                'admin',
                'Runtime Earning Owner',
                ['earning_report']
            );

            $this->actingAs($user);

            $html = view('backend.reports.earning_payout_report', [
                'total_sales_alltime' => 0,
                'sales_this_month' => 0,
                'total_payouts' => 0,
                'payout_this_month' => 0,
                'total_categories' => 0,
                'top_categories' => collect(),
                'total_brands' => 0,
                'top_brands' => collect(),
                'sales_stat' => [],
                'payout_stat' => [],
                'coremarket_reports_context' => [
                    'sellers_enabled' => false,
                    'delivery_enabled' => false,
                    'wallet_enabled' => false,
                    'pos_enabled' => false,
                    'reports_basic_enabled' => true,
                    'reports_advanced_enabled' => true,
                ],
            ])->render();

            $this->assertStringContainsString('"label":"Product Sales"', $html);
            $this->assertStringContainsString('"label":"Customer Subscription"', $html);
            $this->assertStringNotContainsString('"label":"Seller Subscription"', $html);
            $this->assertStringNotContainsString('"label":"Seller Payout"', $html);
            $this->assertStringNotContainsString('"label":"Delivery Boy"', $html);
            $this->assertStringNotContainsString('"label":"Delivery"', $html);
        } finally {
            DB::rollBack();
            Cache::forget('business_settings');
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

    private function emptyPaginator(): LengthAwarePaginator
    {
        return new LengthAwarePaginator(collect(), 0, 15, 1, [
            'path' => '/',
        ]);
    }

    private function dashboardViewData(): array
    {
        $emptyCollection = new Collection();
        $zeroSale = (object) ['total_sale' => 0];

        return [
            'cached_graph_data' => ['num_of_sale_data' => '', 'qty_data' => ''],
            'root_categories' => $emptyCollection,
            'total_customers' => 0,
            'top_customers' => $emptyCollection,
            'total_products' => 0,
            'total_inhouse_products' => 0,
            'total_sellers_products' => 0,
            'total_categories' => 0,
            'top_categories' => $emptyCollection,
            'total_brands' => 0,
            'top_brands' => $emptyCollection,
            'total_sale' => 0,
            'sale_this_month' => 0,
            'admin_sale_this_month' => $zeroSale,
            'seller_sale_this_month' => $zeroSale,
            'sales_stat' => [],
            'total_sellers' => 0,
            'status_wise_sellers' => $emptyCollection,
            'top_sellers' => $emptyCollection,
            'total_order' => 0,
            'total_placed_order' => 0,
            'total_pending_order' => 0,
            'total_confirmed_order' => 0,
            'total_picked_up_order' => 0,
            'total_shipped_order' => 0,
            'total_inhouse_sale' => 0,
            'payment_type_wise_inhouse_sale' => $emptyCollection,
            'inhouse_product_rating' => 0,
            'total_inhouse_order' => 0,
            'coremarket_license_status_card' => ['show' => false],
        ];
    }
}
