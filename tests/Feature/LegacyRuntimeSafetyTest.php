<?php

namespace Tests\Feature;

use Tests\TestCase;

class LegacyRuntimeSafetyTest extends TestCase
{
    public function test_legacy_activation_routes_redirect_home_instead_of_calling_external_runtime_logic(): void
    {
        $this->get('/checkout-payment-detail')->assertRedirect(route('home'));
        $this->get('/customer-products/admin')->assertRedirect(route('home'));
        $this->get('/compare/details/sample-addon')->assertRedirect(route('home'));
        $this->get('/translation-check/sample-addon')->assertRedirect(route('home'));
    }

    public function test_voguepay_callback_is_neutralized(): void
    {
        $this->get('/vogue-pay/callback')
            ->assertOk()
            ->assertSeeText('OK');
    }
}
