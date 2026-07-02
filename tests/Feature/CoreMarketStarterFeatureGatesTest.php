<?php

namespace Tests\Feature;

use Tests\TestCase;

class CoreMarketStarterFeatureGatesTest extends TestCase
{
    public function test_seller_login_surface_is_guarded_when_disabled(): void
    {
        $this->get('/seller/login')->assertStatus(404);
    }

    public function test_sellers_listing_surface_is_guarded_when_disabled(): void
    {
        $this->get('/sellers')->assertStatus(404);
    }

    public function test_delivery_boy_login_surface_is_guarded_when_disabled(): void
    {
        $this->get('/deliveryboy/login')->assertStatus(404);
    }
}
