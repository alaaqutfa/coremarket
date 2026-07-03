<?php

namespace Tests\Feature;

use Tests\TestCase;

class CoreMarketStarterFeatureGatesTest extends TestCase
{
    public function test_homepage_hides_single_store_blocked_surfaces(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertDontSee('Become a Seller !', false);
        $response->assertDontSee('Login to Seller', false);
        $response->assertDontSee('Login to Seller Panel', false);
        $response->assertDontSee('Download Seller App', false);
        $response->assertDontSee('Download Delivery Boy App', false);
    }

    public function test_seller_login_surface_is_guarded_when_disabled(): void
    {
        $this->get('/seller/login')->assertStatus(404);
    }

    public function test_sellers_listing_surface_is_guarded_when_disabled(): void
    {
        $this->get('/sellers')->assertStatus(404);
    }

    public function test_shop_public_pages_are_guarded_when_vendor_mode_is_disabled(): void
    {
        $this->get('/shop/example-store')->assertStatus(404);
        $this->get('/shop/example-store/top-selling')->assertStatus(404);
    }

    public function test_delivery_boy_login_surface_is_guarded_when_disabled(): void
    {
        $this->get('/deliveryboy/login')->assertStatus(404);
    }

    public function test_payment_types_api_does_not_expose_online_gateways_when_disabled(): void
    {
        $response = $this->getJson('/api/v2/payment-types?list=online');

        $response->assertOk();

        $paymentTypeKeys = collect($response->json())->pluck('payment_type_key');

        $this->assertFalse($paymentTypeKeys->contains('paypal'));
        $this->assertFalse($paymentTypeKeys->contains('stripe'));
        $this->assertFalse($paymentTypeKeys->contains('sslcommerz'));
        $this->assertFalse($paymentTypeKeys->contains('paytm'));
        $this->assertFalse($paymentTypeKeys->contains('myfatoorah'));
        $this->assertFalse($paymentTypeKeys->contains('wallet'));
    }
}
