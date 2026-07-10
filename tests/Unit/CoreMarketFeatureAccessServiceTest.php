<?php

namespace Tests\Unit;

use App\Models\BusinessSetting;
use App\Services\CoreMarketFeatureAccessService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CoreMarketFeatureAccessServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('business_settings')) {
            Schema::create('business_settings', function (Blueprint $table) {
                $table->id();
                $table->string('type')->nullable();
                $table->string('lang')->nullable();
                $table->longText('value')->nullable();
                $table->timestamps();
            });
        }

        $this->clearPersistedRuntimeSnapshot();
    }

    public function test_applied_plan_uses_starter_alias_and_normalization(): void
    {
        $this->clearPersistedRuntimeSnapshot();
        config()->set('coremarket.runtime.applied_plan_code', 'ecommerce_starter');

        $service = app(CoreMarketFeatureAccessService::class);

        $this->assertSame('starter', $service->appliedPlan());
        $this->assertSame('starter', coremarket_applied_plan());
    }

    public function test_persisted_runtime_snapshot_takes_precedence_over_config_fallback(): void
    {
        config()->set('coremarket.runtime.applied_plan_code', 'ecommerce_starter');
        config()->set('coremarket.runtime.store_mode', 'single_store');

        $appliedPlan = BusinessSetting::query()
            ->where('type', 'coremarket_runtime_applied_plan')
            ->whereNull('lang')
            ->first() ?: new BusinessSetting();
        $appliedPlan->forceFill(['type' => 'coremarket_runtime_applied_plan', 'lang' => null, 'value' => 'marketplace'])->save();

        $storeMode = BusinessSetting::query()
            ->where('type', 'coremarket_runtime_store_mode')
            ->whereNull('lang')
            ->first() ?: new BusinessSetting();
        $storeMode->forceFill(['type' => 'coremarket_runtime_store_mode', 'lang' => null, 'value' => 'marketplace'])->save();

        Cache::forget('business_settings');

        $service = app(CoreMarketFeatureAccessService::class);

        $this->assertSame('marketplace', $service->appliedPlan());
        $this->assertSame('marketplace', $service->storeMode());
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
        $this->assertSame(2, $service->limit('staff_limit'));
        $this->assertSame(0, $service->limit('sellers_limit'));
        $this->assertSame(256, $service->limit('storage_mb_limit'));
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
        $this->assertSame(20, $service->limit('sellers_limit'));
        $this->assertSame(5120, $service->limit('storage_mb_limit'));
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
        $this->assertSame(20, $matrix['limits']['sellers_limit']);
        $this->assertSame(5120, $matrix['limits']['storage_mb_limit']);
    }

    private function clearPersistedRuntimeSnapshot(): void
    {
        if (! Schema::hasTable('business_settings')) {
            return;
        }

        BusinessSetting::query()
            ->whereIn('type', [
                'coremarket_runtime_status',
                'coremarket_runtime_applied_plan',
                'coremarket_runtime_store_mode',
                'coremarket_runtime_features',
                'coremarket_runtime_limits',
                'coremarket_runtime_store_metadata',
                'coremarket_runtime_support_metadata',
            ])
            ->delete();

        Cache::forget('business_settings');
    }
}
