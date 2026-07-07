<?php

namespace Tests\Unit;

use App\Services\CoreMarketFeatureAccessService;
use Tests\TestCase;

class CoreMarketFeatureAccessServiceTest extends TestCase
{
    public function test_applied_plan_uses_starter_alias_and_normalization(): void
    {
        config()->set('coremarket.runtime.applied_plan_code', 'ecommerce_starter');

        $service = app(CoreMarketFeatureAccessService::class);

        $this->assertSame('starter', $service->appliedPlan());
        $this->assertSame('starter', coremarket_applied_plan());
    }

    public function test_store_mode_uses_plan_default_when_not_explicitly_set(): void
    {
        config()->set('coremarket.runtime.applied_plan_code', 'marketplace');
        config()->set('coremarket.runtime.store_mode', null);
        config()->set('coremarket.license.store_mode', null);

        $service = app(CoreMarketFeatureAccessService::class);

        $this->assertSame('marketplace', $service->storeMode());
        $this->assertSame('marketplace', coremarket_store_mode());
    }

    public function test_new_feature_keys_resolve_from_plan_matrix(): void
    {
        config()->set('coremarket.runtime.applied_plan_code', 'starter');
        config()->set('coremarket.runtime.store_mode', 'single_store');

        $service = app(CoreMarketFeatureAccessService::class);

        $this->assertFalse($service->enabled('multi_vendor'));
        $this->assertTrue($service->enabled('marketing_basic'));
        $this->assertSame(3, $service->limit('staff_limit'));
        $this->assertSame(0, $service->limit('sellers_limit'));
    }

    public function test_explicit_legacy_feature_override_takes_precedence(): void
    {
        config()->set('coremarket.features.pos_enabled', true);
        config()->set('coremarket.runtime.applied_plan_code', 'starter');

        $service = app(CoreMarketFeatureAccessService::class);

        $this->assertTrue($service->enabled('pos_enabled'));
        $this->assertTrue(coremarket_feature_enabled('pos_enabled'));
    }

    public function test_marketplace_plan_enables_marketplace_features(): void
    {
        config()->set('coremarket.runtime.applied_plan_code', 'marketplace');
        config()->set('coremarket.runtime.store_mode', 'marketplace');

        $service = app(CoreMarketFeatureAccessService::class);

        $this->assertTrue($service->enabled('multi_vendor'));
        $this->assertTrue($service->enabled('sellers'));
        $this->assertSame(1000, $service->limit('sellers_limit'));
    }

    public function test_matrix_for_without_explicit_arguments_uses_current_runtime_plan_and_mode(): void
    {
        config()->set('coremarket.runtime.applied_plan_code', 'marketplace');
        config()->set('coremarket.runtime.store_mode', 'marketplace');

        $service = app(CoreMarketFeatureAccessService::class);
        $matrix = $service->matrixFor();

        $this->assertSame('marketplace', $matrix['applied_plan']);
        $this->assertSame('marketplace', $matrix['store_mode']);
        $this->assertTrue($matrix['features']['multi_vendor']);
        $this->assertSame(1000, $matrix['limits']['sellers_limit']);
    }
}
