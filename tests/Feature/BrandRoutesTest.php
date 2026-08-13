<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class BrandRoutesTest extends TestCase
{
    public function test_admin_and_api_brand_indexes_and_legacy_admin_routes_are_unique(): void
    {
        $admin = Route::getRoutes()->getByName('brands.index');
        $api = Route::getRoutes()->getByName('api.brands.index');
        $edit = Route::getRoutes()->getByName('brands.edit');
        $destroy = Route::getRoutes()->getByName('brands.destroy');

        $this->assertSame('admin/brands', $admin->uri());
        $this->assertSame('api/v2/brands', $api->uri());
        $this->assertSame('admin/brands/edit/{id}', $edit->uri());
        $this->assertSame('admin/brands/destroy/{id}', $destroy->uri());
        $this->assertStringContainsString('App\\Http\\Controllers\\BrandController@index', $admin->getActionName());
        $this->assertStringContainsString('App\\Http\\Controllers\\Api\\V2\\BrandController@index', $api->getActionName());
    }
}
