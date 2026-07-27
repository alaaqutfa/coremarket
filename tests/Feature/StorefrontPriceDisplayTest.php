<?php

namespace Tests\Feature;

use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use App\Services\CoreMarketStorefrontPriceDisplayService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StorefrontPriceDisplayTest extends TestCase
{
    use DatabaseTransactions;

    private CoreMarketStorefrontPriceDisplayService $display;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('coremarket_testing', DB::getDatabaseName());
        config()->set('coremarket.pricing.price_lists_enabled', true);
        config()->set('coremarket.pricing.priority', 'customer_price_first');
        DB::table('business_settings')->updateOrInsert(
            ['type' => 'pricing.price_lists_enabled', 'lang' => null],
            ['value' => '1', 'updated_at' => now(), 'created_at' => now()]
        );
        Cache::forget('business_settings');
        $this->display = app(CoreMarketStorefrontPriceDisplayService::class);
    }

    public function test_guest_and_customer_without_price_list_see_public_sale_price(): void
    {
        [$product] = $this->product(['discount' => 20, 'discount_type' => 'amount']);

        Auth::logout();
        $guest = $this->display->display($product);
        $customer = $this->display->display($product, $this->customer());

        $this->assertSame('sale_price', $guest['price_source']);
        $this->assertSame(80.0, $guest['display_price']);
        $this->assertFalse($guest['has_customer_price']);
        $this->assertSame($guest['display_price'], $customer['display_price']);
    }

    public function test_assigned_customer_sees_only_their_price_when_feature_is_enabled(): void
    {
        [$product, $stock] = $this->product(['discount' => 20, 'discount_type' => 'amount']);
        $customerA = $this->customerWithPrice($product, $stock, 60);
        $customerB = $this->customerWithPrice($product, $stock, 70);

        $priceA = $this->display->display($stock, $customerA);
        $priceB = $this->display->display($stock, $customerB);
        $this->actingAs($customerA);
        $sharedStorefrontHelperPrice = coremarket_storefront_price($product);
        Auth::logout();
        $guestAfterCustomers = $this->display->display($stock);

        $this->assertSame(60.0, $priceA['display_price']);
        $this->assertSame(60.0, $sharedStorefrontHelperPrice['display_price']);
        $this->assertSame(70.0, $priceB['display_price']);
        $this->assertSame('price_list', $priceA['price_source']);
        $this->assertNotSame($priceA['price_list_id'], $priceB['price_list_id']);
        $this->assertSame(80.0, $guestAfterCustomers['display_price']);
        $this->assertNull($guestAfterCustomers['price_list_id']);
    }

    public function test_disabled_feature_falls_back_without_deleting_price_lists(): void
    {
        [$product, $stock] = $this->product(['discount' => 20, 'discount_type' => 'amount']);
        $customer = $this->customerWithPrice($product, $stock, 60);
        config()->set('coremarket.pricing.price_lists_enabled', false);
        DB::table('business_settings')->where('type', 'pricing.price_lists_enabled')->update(['value' => '0']);
        Cache::forget('business_settings');

        $price = $this->display->display($stock, $customer);

        $this->assertSame(80.0, $price['display_price']);
        $this->assertSame('sale_price', $price['price_source']);
        $this->assertSame(1, PriceList::query()->count());
    }

    public function test_supported_priorities_keep_sale_and_price_list_separate(): void
    {
        [$product, $stock] = $this->product(['discount' => 20, 'discount_type' => 'amount']);
        $customer = $this->customerWithPrice($product, $stock, 90);

        $customerFirst = $this->display->display($stock, $customer, ['priority' => 'customer_price_first']);
        $saleFirst = $this->display->display($stock, $customer, ['priority' => 'sale_price_first']);
        $lowest = $this->display->display($stock, $customer, ['priority' => 'lowest_price']);

        $this->assertSame(['price_list', 90.0], [$customerFirst['price_source'], $customerFirst['display_price']]);
        $this->assertSame(['sale_price', 80.0], [$saleFirst['price_source'], $saleFirst['display_price']]);
        $this->assertSame(['sale_price', 80.0], [$lowest['price_source'], $lowest['display_price']]);
        $this->assertSame(80.0, $customerFirst['sale_price']);
    }

    public function test_non_customer_session_cannot_receive_customer_specific_price(): void
    {
        [$product, $stock] = $this->product();
        $staff = $this->customerWithPrice($product, $stock, 50);
        $staff->forceFill(['user_type' => 'staff'])->save();

        $price = $this->display->display($stock, $staff);

        $this->assertSame(100.0, $price['display_price']);
        $this->assertSame('regular_price', $price['price_source']);
    }

    public function test_storefront_formatting_never_exceeds_two_decimals(): void
    {
        DB::table('business_settings')->updateOrInsert(
            ['type' => 'no_of_decimals', 'lang' => null],
            ['value' => '4', 'updated_at' => now(), 'created_at' => now()]
        );
        Cache::forget('business_settings');
        [$product, $stock] = $this->product();
        $customer = $this->customerWithPrice($product, $stock, 61.239);

        $price = $this->display->display($stock, $customer);

        $this->assertSame(61.24, $price['display_price']);
        $this->assertDoesNotMatchRegularExpression('/[.,]\d{3,}/', $price['formatted_display_price']);
    }

    private function product(array $attributes = []): array
    {
        $now = now();
        $owner = $this->customer();
        $productId = DB::table('products')->insertGetId(array_merge([
            'name' => 'Storefront Price Product '.uniqid(),
            'user_id' => $owner->id,
            'category_id' => 1,
            'unit_price' => 100,
            'purchase_price' => 40,
            'current_stock' => 10,
            'slug' => 'storefront-price-'.uniqid(),
            'created_at' => $now,
            'updated_at' => $now,
        ], $attributes));
        $stockId = DB::table('product_stocks')->insertGetId([
            'product_id' => $productId,
            'variant' => '',
            'sku' => 'STOREFRONT-'.uniqid(),
            'price' => 100,
            'qty' => 10,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            Product::query()->findOrFail($productId),
            ProductStock::query()->with('product')->findOrFail($stockId),
        ];
    }

    private function customerWithPrice(Product $product, ProductStock $stock, float $price): User
    {
        $list = PriceList::query()->create([
            'name' => 'Customer List '.uniqid(),
            'code' => 'CUSTOMER-'.strtoupper(uniqid()),
            'type' => 'custom',
            'pricing_method' => 'fixed_price',
            'currency' => 'USD',
            'is_active' => true,
        ]);
        PriceListItem::query()->create([
            'price_list_id' => $list->id,
            'product_id' => $product->id,
            'product_stock_id' => $stock->id,
            'fixed_price' => $price,
            'is_active' => true,
        ]);
        $customer = $this->customer();
        $customer->forceFill(['price_list_id' => $list->id])->save();

        return $customer;
    }

    private function customer(): User
    {
        $customer = User::query()->create([
            'name' => 'Storefront Customer',
            'email' => 'storefront-'.uniqid().'@example.test',
            'password' => 'testing-password',
        ]);
        $customer->forceFill(['user_type' => 'customer', 'banned' => 0])->save();

        return $customer;
    }
}
