<?php

namespace Tests\Unit;

use App\Models\BusinessSetting;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CustomerSellerIdentityVisibilityTest extends TestCase
{
    public function test_seller_identity_is_visible_by_default_for_existing_stores(): void
    {
        Cache::shouldReceive('remember')
            ->once()
            ->andReturn(collect());

        $this->assertTrue(customer_seller_identity_visible());
    }

    public function test_seller_identity_can_be_hidden_by_business_setting(): void
    {
        Cache::shouldReceive('remember')
            ->once()
            ->andReturn(collect([
                (new BusinessSetting)->forceFill([
                    'type' => 'customer_seller_identity_visible',
                    'value' => '0',
                ]),
            ]));

        $this->assertFalse(customer_seller_identity_visible());
    }
}
