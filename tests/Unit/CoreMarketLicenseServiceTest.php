<?php

namespace Tests\Unit;

use App\Services\CoreMarketLicenseService;
use Carbon\Carbon;
use Tests\TestCase;

class CoreMarketLicenseServiceTest extends TestCase
{
    public function test_license_service_active_status_works(): void
    {
        $service = $this->makeService([
            'license_enabled' => true,
            'status' => 'active',
        ]);

        $this->assertSame('active', $service->status());
        $this->assertTrue($service->isActive());
        $this->assertTrue($service->canManageStore());
        $this->assertTrue($service->canAcceptOrders());
    }

    public function test_license_service_expired_status_blocks_management_outside_grace(): void
    {
        $service = $this->makeService([
            'license_enabled' => true,
            'status' => 'expired',
            'expires_at' => '2026-01-01 00:00:00',
            'grace_until' => '2026-01-10 00:00:00',
        ]);

        $now = Carbon::parse('2026-02-01 00:00:00');

        $this->assertTrue($service->isExpired($now));
        $this->assertFalse($service->isInGracePeriod($now));
        $this->assertFalse($service->canManageStore(null, $now));
        $this->assertFalse($service->canAcceptOrders(null, $now));
    }

    public function test_license_grace_period_works(): void
    {
        $service = $this->makeService([
            'license_enabled' => true,
            'status' => 'expired',
            'expires_at' => '2026-01-01 00:00:00',
            'grace_until' => '2026-01-10 00:00:00',
        ]);

        $now = Carbon::parse('2026-01-05 00:00:00');

        $this->assertTrue($service->isExpired($now));
        $this->assertTrue($service->isInGracePeriod($now));
        $this->assertTrue($service->canManageStore(null, $now));
        $this->assertTrue($service->canAcceptOrders(null, $now));
    }

    public function test_product_limit_allows_create_below_limit(): void
    {
        $service = $this->makeService([
            'limits' => [
                'products_limit' => 50,
                'monthly_orders_limit' => 300,
            ],
        ]);

        $this->assertTrue($service->canCreateProducts(1, 49));
    }

    public function test_product_limit_blocks_create_above_limit(): void
    {
        $service = $this->makeService([
            'limits' => [
                'products_limit' => 50,
                'monthly_orders_limit' => 300,
            ],
        ]);

        $this->assertFalse($service->canCreateProducts(1, 50));
        $this->assertFalse($service->canCreateProducts(5, 48));
    }

    public function test_existing_product_publish_check_does_not_block_already_published_product(): void
    {
        $service = $this->makeService();

        $this->assertTrue($service->canPublishProduct(true, 999));
    }

    public function test_monthly_order_limit_blocks_new_order_when_reached(): void
    {
        $service = $this->makeService([
            'limits' => [
                'products_limit' => 50,
                'monthly_orders_limit' => 300,
            ],
        ]);

        $this->assertFalse($service->canCreateOrders(1, 300));
        $this->assertTrue($service->canCreateOrders(1, 299));
    }

    public function test_license_service_returns_usage_counts_and_percentages(): void
    {
        $service = $this->makeService([
            'limits' => [
                'products_limit' => 50,
                'monthly_orders_limit' => 300,
            ],
        ]);

        $this->assertSame(24, $service->productUsagePercentage(12));
        $this->assertSame(25, $service->monthlyOrderUsagePercentage(75));
    }

    public function test_near_limit_calculation_works(): void
    {
        $service = $this->makeService([
            'limits' => [
                'products_limit' => 50,
                'monthly_orders_limit' => 300,
            ],
        ]);

        $this->assertTrue($service->isNearProductLimit(40));
        $this->assertTrue($service->isNearMonthlyOrderLimit(240));
        $this->assertFalse($service->isNearProductLimit(20));
        $this->assertFalse($service->isNearMonthlyOrderLimit(60));
    }

    public function test_limit_reached_calculation_works(): void
    {
        $service = $this->makeService([
            'limits' => [
                'products_limit' => 50,
                'monthly_orders_limit' => 300,
            ],
        ]);

        $this->assertTrue($service->isProductLimitReached(50));
        $this->assertTrue($service->isMonthlyOrderLimitReached(300));
        $this->assertFalse($service->isProductLimitReached(49));
        $this->assertFalse($service->isMonthlyOrderLimitReached(299));
    }

    public function test_super_admin_can_bypass_license_lock_if_needed(): void
    {
        $service = $this->makeService([
            'license_enabled' => true,
            'status' => 'suspended',
        ]);

        $user = new class {
            public string $user_type = 'admin';

            public function hasRole(string $role): bool
            {
                return $role === 'Super Admin';
            }
        };

        $this->assertTrue($service->canManageStore($user));
        $this->assertTrue($service->canAcceptOrders($user));
    }

    public function test_store_admin_cannot_bypass_license_lock(): void
    {
        $service = $this->makeService([
            'license_enabled' => true,
            'status' => 'suspended',
        ]);

        $user = new class {
            public string $user_type = 'staff';

            public function hasRole(string $role): bool
            {
                return $role === 'store_admin';
            }
        };

        $this->assertFalse($service->canManageStore($user));
    }

    private function makeService(array $overrides = []): CoreMarketLicenseService
    {
        $licenseOverrides = $overrides;
        unset($licenseOverrides['limits'], $licenseOverrides['features']);

        $license = array_merge([
            'license_enabled' => false,
            'instance_id' => null,
            'license_key' => null,
            'domain' => 'localhost',
            'applied_plan_code' => 'starter',
            'plan_code' => 'starter',
            'store_mode' => 'single_store',
            'status' => 'active',
            'starts_at' => null,
            'expires_at' => null,
            'grace_until' => null,
            'suspension_reason' => null,
            'feature_overrides' => [],
            'limit_overrides' => [],
        ], $licenseOverrides);

        $limits = $overrides['limits'] ?? [
            'products_limit' => 50,
            'monthly_orders_limit' => 300,
        ];

        $features = $overrides['features'] ?? config('coremarket.features', []);

        config()->set('coremarket.license', $license);
        config()->set('coremarket.limits', $limits);
        config()->set('coremarket.features', $features);

        return app(CoreMarketLicenseService::class);
    }
}
