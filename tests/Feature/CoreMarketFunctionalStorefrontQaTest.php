<?php

namespace Tests\Feature;

use Tests\TestCase;

class CoreMarketFunctionalStorefrontQaTest extends TestCase
{
    public function test_search_page_handles_keywords_without_matching_categories(): void
    {
        $this->get('/search?keyword=rice')->assertOk();
    }

    public function test_guest_checkout_redirects_to_login(): void
    {
        $response = $this->get('/checkout');

        $response->assertRedirect();
        $this->assertContains(
            $response->headers->get('Location'),
            [
                url('/users/login'),
                url('/email/verify'),
            ]
        );
    }
}
