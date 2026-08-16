<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class GeneralSettingsAccessTest extends TestCase
{
    public function test_general_settings_route_requires_a_super_admin(): void
    {
        $route = Route::getRoutes()->getByName('general_setting.index');

        $this->assertNotNull($route);
        $this->assertContains('super_admin', $route->gatherMiddleware());
    }
}
