<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DigitalProductRoutesTest extends TestCase
{
    public function test_legacy_digital_product_edit_and_destroy_routes_remain_the_named_admin_routes(): void
    {
        $edit = Route::getRoutes()->getByName('digitalproducts.edit');
        $destroy = Route::getRoutes()->getByName('digitalproducts.destroy');

        $this->assertSame('admin/digitalproducts/edit/{id}', $edit->uri());
        $this->assertSame('admin/digitalproducts/destroy/{id}', $destroy->uri());
        $this->assertStringContainsString('DigitalProductController@edit', $edit->getActionName());
        $this->assertStringContainsString('DigitalProductController@destroy', $destroy->getActionName());
    }
}
