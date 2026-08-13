<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CategoryRoutesTest extends TestCase
{
    public function test_admin_and_api_category_indexes_have_distinct_names_and_keep_their_routes(): void
    {
        $admin = Route::getRoutes()->getByName('categories.index');
        $api = Route::getRoutes()->getByName('api.categories.index');

        $this->assertNotNull($admin);
        $this->assertNotNull($api);
        $this->assertSame('admin/categories', $admin->uri());
        $this->assertSame('api/v2/categories', $api->uri());
        $this->assertStringContainsString('App\\Http\\Controllers\\CategoryController@index', $admin->getActionName());
        $this->assertStringContainsString('App\\Http\\Controllers\\Api\\V2\\CategoryController@index', $api->getActionName());
    }

    public function test_category_legacy_edit_and_destroy_routes_remain_the_named_admin_routes(): void
    {
        $edit = Route::getRoutes()->getByName('categories.edit');
        $destroy = Route::getRoutes()->getByName('categories.destroy');

        $this->assertSame('admin/categories/edit/{id}', $edit->uri());
        $this->assertSame('admin/categories/destroy/{id}', $destroy->uri());
        $this->assertStringContainsString('CategoryController@edit', $edit->getActionName());
        $this->assertStringContainsString('CategoryController@destroy', $destroy->getActionName());
    }
}
