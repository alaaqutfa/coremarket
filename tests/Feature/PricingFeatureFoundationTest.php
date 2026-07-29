<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CoreMarketPricingFeatureService;
use Database\Seeders\StaffRolePresetSeeder;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PricingFeatureFoundationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_pricing_flags_default_to_disabled(): void
    {
        DB::table('business_settings')->whereIn('type', [
            'pricing.price_lists_enabled', 'pricing.flexible_selling_price_enabled',
            'pricing.branch_pricing_enabled', 'pricing.branch_pricing_priority',
        ])->delete();
        Cache::forget('business_settings');
        $snapshot = app(CoreMarketPricingFeatureService::class)->snapshot();

        $this->assertFalse($snapshot['price_lists_enabled']);
        $this->assertFalse($snapshot['flexible_selling_price_enabled']);
        $this->assertFalse($snapshot['branch_pricing_enabled']);
        $this->assertSame('branch_price_first', $snapshot['branch_pricing_priority']);
    }

    public function test_flexible_price_override_requires_explicit_flag(): void
    {
        $this->expectException(DomainException::class);
        app(CoreMarketPricingFeatureService::class)->resolveSellingPrice(10, 9);
    }

    public function test_enabled_flexible_price_allows_normalized_service_override(): void
    {
        DB::table('business_settings')->updateOrInsert(
            ['type' => 'pricing.flexible_selling_price_enabled', 'lang' => null],
            ['value' => '1', 'updated_at' => now()]
        );
        Cache::forget('business_settings');

        $this->assertSame(9.13, app(CoreMarketPricingFeatureService::class)->resolveSellingPrice(10, 9.126));
    }

    public function test_client_price_list_management_is_blocked_until_feature_is_enabled(): void
    {
        $this->seed(StaffRolePresetSeeder::class);
        $user = User::query()->create([
            'name' => 'Pricing Manager',
            'email' => 'pricing-manager-' . uniqid() . '@example.test',
            'password' => bcrypt('Temporary123!'),
        ]);
        $user->forceFill(['user_type' => 'staff'])->save();
        $user->syncRoles('owner_general_manager');

        DB::table('business_settings')->where('type', 'pricing.price_lists_enabled')->delete();
        Cache::forget('business_settings');
        $this->actingAs($user)->get(route('operations.price-lists.index'))->assertForbidden();

        DB::table('business_settings')->updateOrInsert(
            ['type' => 'pricing.price_lists_enabled', 'lang' => null],
            ['value' => '1', 'updated_at' => now()]
        );
        Cache::forget('business_settings');
        $this->actingAs($user)->get(route('operations.price-lists.index'))->assertOk();
    }
}
