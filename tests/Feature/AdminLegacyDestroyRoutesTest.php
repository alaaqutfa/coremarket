<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminLegacyDestroyRoutesTest extends TestCase
{
    public function test_legacy_admin_destroy_routes_remain_the_only_named_destroy_routes(): void
    {
        foreach ([
            'sellers.destroy' => 'admin/sellers/destroy/{id}',
            'customers.destroy' => 'admin/customers/destroy/{id}',
            'dynamic-popups.destroy' => 'admin/dynamic-popups/destroy/{id}',
            'custom-alerts.destroy' => 'admin/custom-alerts/destroy/{id}',
            'tax.destroy' => 'admin/tax/destroy/{id}',
            'languages.destroy' => 'admin/languages/destroy/{id}',
            'custom-pages.destroy' => 'admin/website/custom-pages/destroy/{id}',
            'roles.destroy' => 'admin/roles/destroy/{id}',
            'staffs.destroy' => 'admin/staffs/destroy/{id}',
        ] as $name => $uri) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route);
            $this->assertSame($uri, $route->uri());
        }
    }

    public function test_admin_and_api_customer_routes_keep_distinct_names(): void
    {
        $admin = Route::getRoutes()->getByName('customers.show');
        $api = Route::getRoutes()->getByName('api.customers.show');

        $this->assertSame('admin/customers/{customer}', $admin->uri());
        $this->assertSame('api/v2/customers/{customer}', $api->uri());
    }

    public function test_legacy_tax_edit_route_remains_the_named_admin_edit_route(): void
    {
        $edit = Route::getRoutes()->getByName('tax.edit');

        $this->assertSame('admin/tax/edit/{id}', $edit->uri());
    }

    public function test_legacy_language_update_route_remains_the_named_admin_update_route(): void
    {
        $update = Route::getRoutes()->getByName('languages.update');

        $this->assertSame('admin/languages/{id}/update', $update->uri());
        $this->assertContains('POST', $update->methods());
    }

    public function test_legacy_custom_page_and_role_edit_routes_remain_the_named_admin_edit_routes(): void
    {
        $this->assertSame('admin/website/custom-pages/edit/{id}', Route::getRoutes()->getByName('custom-pages.edit')->uri());
        $this->assertSame('admin/roles/edit/{id}', Route::getRoutes()->getByName('roles.edit')->uri());
    }
}
