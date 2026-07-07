<?php

namespace Tests\Unit;

use App\Services\CoreMarketInstanceSetupService;
use Tests\TestCase;

class CoreMarketInstanceSetupServiceTest extends TestCase
{
    public function test_setup_service_builds_plan(): void
    {
        $service = app(CoreMarketInstanceSetupService::class);

        $plan = $service->buildPlan('client-store', [
            'store_name' => 'Client Store',
            'domain' => 'example-store.com',
            'plan' => 'starter',
            'admin_email' => 'owner@example-store.com',
            'dry_run' => true,
        ]);

        $this->assertSame('client-store', $plan['instance_id']);
        $this->assertSame('Client Store', $plan['store_name']);
        $this->assertSame('example-store.com', $plan['domain']);
        $this->assertSame('starter', $plan['plan_code']);
        $this->assertTrue($plan['dry_run']);
    }

    public function test_setup_plan_includes_safe_business_settings_map(): void
    {
        $service = app(CoreMarketInstanceSetupService::class);

        $plan = $service->buildPlan('client-store', [
            'store_name' => 'Client Store',
            'currency' => 'USD',
            'whatsapp' => '+10000000000',
        ]);

        $this->assertArrayHasKey('website_name', $plan['business_settings']);
        $this->assertArrayHasKey('vendor_system_activation', $plan['business_settings']);
        $this->assertSame(0, $plan['business_settings']['vendor_system_activation']);
        $this->assertSame(0, $plan['business_settings']['wallet_system']);
        $this->assertSame('Client Store', $plan['business_settings']['website_name']);
    }
}
