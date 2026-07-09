<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CorePilotRuntimeSnapshotReceiverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('coremarket.runtime_sync.token', 'sync-secret');

        if (! Schema::hasTable('business_settings')) {
            Schema::create('business_settings', function (Blueprint $table) {
                $table->id();
                $table->string('type')->nullable();
                $table->string('lang')->nullable();
                $table->longText('value')->nullable();
                $table->timestamps();
            });
        }
    }

    public function test_invalid_token_is_rejected(): void
    {
        $this->postJson('/api/corepilot/runtime-snapshot/preview', $this->marketplacePayload())
            ->assertStatus(401);

        $this->withHeaders(['X-CorePilot-Sync-Token' => 'wrong-token'])
            ->postJson('/api/corepilot/runtime-snapshot/preview', $this->marketplacePayload())
            ->assertStatus(403);
    }

    public function test_preview_validates_and_writes_nothing(): void
    {
        $before = BusinessSetting::count();

        $response = $this->withHeaders(['X-CorePilot-Sync-Token' => 'sync-secret'])
            ->postJson('/api/corepilot/runtime-snapshot/preview', $this->marketplacePayload());

        $response->assertOk()
            ->assertJsonPath('result', true)
            ->assertJsonPath('mode', 'preview')
            ->assertJsonPath('runtime.applied_plan', 'marketplace')
            ->assertJsonPath('runtime.store_mode', 'marketplace')
            ->assertJsonPath('runtime.features.multi_vendor', true)
            ->assertJsonPath('runtime.limits.storage_mb_limit', 5120)
            ->assertJsonMissingPath('token');

        $this->assertSame($before, BusinessSetting::count());
        $this->assertDatabaseMissing('business_settings', ['type' => 'coremarket_runtime_applied_plan']);
    }

    public function test_apply_stores_plan_mode_features_limits_and_metadata(): void
    {
        $response = $this->withHeaders(['X-CorePilot-Sync-Token' => 'sync-secret'])
            ->postJson('/api/corepilot/runtime-snapshot/apply', $this->marketplacePayload());

        $response->assertOk()
            ->assertJsonPath('result', true)
            ->assertJsonPath('mode', 'apply')
            ->assertJsonPath('runtime.runtime.applied_plan', 'marketplace')
            ->assertJsonPath('runtime.runtime.features.multi_vendor', true)
            ->assertJsonPath('runtime.runtime.limits.storage_mb_limit', 5120)
            ->assertJsonMissingPath('api_token')
            ->assertJsonMissingPath('token');

        $this->assertSame('marketplace', BusinessSetting::query()->where('type', 'coremarket_runtime_applied_plan')->whereNull('lang')->value('value'));
        $this->assertSame('marketplace', BusinessSetting::query()->where('type', 'coremarket_runtime_store_mode')->whereNull('lang')->value('value'));
        $this->assertSame('active', BusinessSetting::query()->where('type', 'coremarket_runtime_status')->whereNull('lang')->value('value'));
        $this->assertSame('1', (string) BusinessSetting::query()->where('type', 'vendor_system_activation')->whereNull('lang')->value('value'));
    }

    public function test_invalid_plan_returns_422_not_500(): void
    {
        $payload = $this->marketplacePayload();
        $payload['applied_plan'] = 'broken-plan';

        $this->withHeaders(['X-CorePilot-Sync-Token' => 'sync-secret'])
            ->postJson('/api/corepilot/runtime-snapshot/apply', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('applied_plan');
    }

    public function test_invalid_store_mode_returns_422_not_500(): void
    {
        $payload = $this->marketplacePayload();
        $payload['store_mode'] = 'broken-mode';

        $this->withHeaders(['X-CorePilot-Sync-Token' => 'sync-secret'])
            ->postJson('/api/corepilot/runtime-snapshot/apply', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('store_mode');
    }

    public function test_missing_limits_payload_is_handled_safely_with_runtime_defaults(): void
    {
        $payload = $this->starterPayload();
        unset($payload['limits']);

        $response = $this->withHeaders(['X-CorePilot-Sync-Token' => 'sync-secret'])
            ->postJson('/api/corepilot/runtime-snapshot/apply', $payload);

        $response->assertOk()
            ->assertJsonPath('runtime.runtime.applied_plan', 'starter')
            ->assertJsonPath('runtime.runtime.store_mode', 'single_store')
            ->assertJsonPath('runtime.runtime.limits.products_limit', 50)
            ->assertJsonPath('runtime.runtime.limits.storage_mb_limit', 256);
    }

    public function test_runtime_storage_failure_returns_generic_500_without_exposing_token(): void
    {
        Schema::drop('business_settings');

        $this->withHeaders(['X-CorePilot-Sync-Token' => 'sync-secret'])
            ->postJson('/api/corepilot/runtime-snapshot/apply', $this->marketplacePayload())
            ->assertStatus(500)
            ->assertJson([
                'message' => 'CoreMarket runtime receiver failed. Check CoreMarket logs.',
            ])
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('api_token');
    }

    public function test_applied_marketplace_snapshot_enables_sellers_and_multi_vendor(): void
    {
        $this->withHeaders(['X-CorePilot-Sync-Token' => 'sync-secret'])
            ->postJson('/api/corepilot/runtime-snapshot/apply', $this->marketplacePayload())
            ->assertOk();

        $this->assertSame('marketplace', coremarket_applied_plan());
        $this->assertSame('marketplace', coremarket_store_mode());
        $this->assertTrue(coremarket_feature_enabled('multi_vendor'));
        $this->assertTrue(coremarket_feature_enabled('sellers'));
        $this->assertSame(1200, coremarket_limit('sellers_limit'));
        $this->assertSame(5120, coremarket_limit('storage_mb_limit'));
    }

    public function test_applied_starter_single_store_snapshot_disables_sellers_and_multi_vendor(): void
    {
        $this->withHeaders(['X-CorePilot-Sync-Token' => 'sync-secret'])
            ->postJson('/api/corepilot/runtime-snapshot/apply', $this->starterPayload())
            ->assertOk();

        $this->assertSame('starter', coremarket_applied_plan());
        $this->assertSame('single_store', coremarket_store_mode());
        $this->assertFalse(coremarket_feature_enabled('multi_vendor'));
        $this->assertFalse(coremarket_feature_enabled('sellers'));
        $this->assertSame(0, coremarket_limit('sellers_limit'));
        $this->assertSame(256, coremarket_limit('storage_mb_limit'));
        $this->assertSame('0', (string) BusinessSetting::query()->where('type', 'vendor_system_activation')->whereNull('lang')->value('value'));
    }

    private function marketplacePayload(): array
    {
        return [
            'status' => 'active',
            'applied_plan' => 'marketplace',
            'store_mode' => 'marketplace',
            'features' => [
                'multi_vendor' => true,
                'sellers' => true,
                'pos' => true,
                'subscription_page' => true,
            ],
            'limits' => [
                'products_limit' => 5000,
                'monthly_orders_limit' => 25000,
                'sellers_limit' => 1200,
                'storage_mb_limit' => 5120,
            ],
            'store' => [
                'instance_id' => 'market-001',
                'store_name' => 'Marketplace One',
                'store_url' => 'https://market.example.test',
                'admin_url' => 'https://market.example.test/admin',
                'api_base_url' => 'https://market.example.test/api',
            ],
            'support' => [
                'company_name' => 'CorePilot Commerce',
                'support_email' => 'support@example.test',
            ],
        ];
    }

    private function starterPayload(): array
    {
        return [
            'status' => 'inactive',
            'applied_plan' => 'starter',
            'store_mode' => 'single_store',
            'features' => [
                'multi_vendor' => false,
                'sellers' => false,
                'pos' => false,
                'subscription_page' => true,
            ],
            'limits' => [
                'products_limit' => 50,
                'monthly_orders_limit' => 300,
                'sellers_limit' => 0,
                'storage_mb_limit' => 256,
            ],
            'store' => [
                'instance_id' => 'starter-001',
                'store_name' => 'Starter Store',
                'store_url' => 'https://starter.example.test',
            ],
            'support' => [
                'company_name' => 'Starter Support',
                'support_email' => 'starter-support@example.test',
            ],
        ];
    }
}
