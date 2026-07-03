<?php

namespace Tests\Unit;

use Illuminate\Http\Request;
use Tests\TestCase;

class CoreMarketRuntimeHelperTest extends TestCase
{
    public function test_runtime_host_falls_back_to_configured_app_url(): void
    {
        config(['app.url' => 'https://example-store.com']);
        app()->instance('request', Request::create('https://example-store.com'));

        $this->assertSame('example-store.com', coremarketRuntimeHost());
    }

    public function test_local_host_detection_is_safe(): void
    {
        $this->assertTrue(coremarketIsLocalHost('localhost'));
        $this->assertTrue(coremarketIsLocalHost('127.0.0.1'));
        $this->assertFalse(coremarketIsLocalHost('example-store.com'));
    }

    public function test_store_name_falls_back_to_app_name_when_settings_are_missing(): void
    {
        config(['app.name' => 'Dynamic Store']);

        $this->assertSame('Dynamic Store', coremarketStoreName());
    }

    public function test_store_name_uses_coremarket_as_last_fallback(): void
    {
        config(['app.name' => null]);

        $this->assertSame('CoreMarket', coremarketStoreName());
    }

    public function test_store_name_skips_known_legacy_brands(): void
    {
        $this->assertTrue(coremarketIsLegacyBrandValue('Coin Market'));
        $this->assertTrue(coremarketIsLegacyBrandValue('Syrian Souq'));
        $this->assertTrue(coremarketIsLegacyBrandValue('Active eCommerce CMS'));
        $this->assertFalse(coremarketIsLegacyBrandValue('Modern Store'));
    }
}
