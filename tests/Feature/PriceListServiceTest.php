<?php

namespace Tests\Feature;

use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductStock;
use App\Models\User;
use App\Services\CoreMarketPriceListService;
use App\Services\CashboxService;
use App\Services\WebPosService;
use App\Utility\CartUtility;
use Database\Seeders\PriceListSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PriceListServiceTest extends TestCase
{
    use DatabaseTransactions;

    private CoreMarketPriceListService $prices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('coremarket_testing', DB::getDatabaseName());
        $this->prices = app(CoreMarketPriceListService::class);
    }

    public function test_default_lists_are_idempotent_and_customer_can_be_assigned(): void
    {
        $this->seed(PriceListSeeder::class);
        $this->seed(PriceListSeeder::class);

        $this->assertSame(5, PriceList::query()->whereIn('code', ['RETAIL', 'WHOLESALE-A', 'WHOLESALE-B', 'WHOLESALE-C', 'VIP'])->count());
        $customer = $this->customer();
        $list = PriceList::query()->where('code', 'WHOLESALE-A')->firstOrFail();
        $customer->forceFill(['price_list_id' => $list->id])->save();

        $this->assertSame($list->id, $customer->fresh()->priceList->id);
    }

    public function test_fixed_customer_price_overrides_sale_and_regular_by_default(): void
    {
        [$stock, $customer, $list] = $this->subject('fixed_price', ['discount' => 25, 'discount_type' => 'percent']);
        $this->item($list, $stock, ['fixed_price' => 60]);

        $snapshot = $this->prices->pricingSnapshot($stock, $customer);

        $this->assertSame('price_list', $snapshot['source']);
        $this->assertSame(60.0, $snapshot['resolved_price']);
        $this->assertSame(75.0, $snapshot['sale_price']);
        $this->assertSame(100.0, $snapshot['base_regular_price']);
    }

    public function test_margin_and_discount_methods_calculate_from_cost_and_regular(): void
    {
        [$marginStock, $marginCustomer, $marginList] = $this->subject('margin_over_cost');
        $this->item($marginList, $marginStock, ['margin_percent' => 50]);
        [$discountStock, $discountCustomer, $discountList] = $this->subject('discount_from_regular');
        $this->item($discountList, $discountStock, ['discount_percent' => 20]);

        $this->assertSame(60.0, $this->prices->resolvePrice($marginStock, $marginCustomer));
        $this->assertSame(80.0, $this->prices->resolvePrice($discountStock, $discountCustomer));
    }

    public function test_priority_options_keep_sale_price_separate_and_support_lowest_price(): void
    {
        [$stock, $customer, $list] = $this->subject('fixed_price', ['discount' => 25, 'discount_type' => 'percent']);
        $this->item($list, $stock, ['fixed_price' => 80]);

        $saleFirst = $this->prices->pricingSnapshot($stock, $customer, ['priority' => 'sale_price_first']);
        $lowest = $this->prices->pricingSnapshot($stock, $customer, ['priority' => 'lowest_price']);

        $this->assertSame('sale_price', $saleFirst['source']);
        $this->assertSame(75.0, $saleFirst['resolved_price']);
        $this->assertSame('sale_price', $lowest['source']);
        $this->assertSame(75.0, $lowest['resolved_price']);
    }

    public function test_expired_item_and_inactive_list_fall_back_to_regular_price(): void
    {
        [$stock, $customer, $list] = $this->subject('fixed_price');
        $this->item($list, $stock, ['fixed_price' => 50, 'ends_at' => now()->subMinute()]);
        $this->assertSame(100.0, $this->prices->resolvePrice($stock, $customer));

        $list->update(['is_active' => false]);
        $this->assertSame(100.0, $this->prices->resolvePrice($stock, $customer));
    }

    public function test_pos_and_web_cart_resolve_the_assigned_customer_price_to_two_decimals(): void
    {
        [$stock, $customer, $list] = $this->subject('fixed_price');
        $this->item($list, $stock, ['fixed_price' => 61.239]);
        $stock->load('product.taxes');

        $posLine = app(WebPosService::class)->buildCartLine($stock, 1, $customer);
        $this->actingAs($customer);
        $webPrice = CartUtility::get_price($stock->product, $stock, 1);

        $this->assertSame(61.24, $posLine['unit_price']);
        $this->assertSame('price_list', $posLine['pricing_snapshot']['source']);
        $this->assertSame(61.24, $webPrice);
        $this->assertSame('61.24 USD', coremarket_money($webPrice, 'USD'));
    }

    public function test_pos_checkout_recalculates_customer_price_on_the_server(): void
    {
        [$stock, $customer, $list] = $this->subject('fixed_price');
        $this->item($list, $stock, ['fixed_price' => 55]);
        $cashier = new User();
        $cashier->forceFill([
            'name' => 'Price List Cashier',
            'email' => 'price-cashier-' . uniqid() . '@example.test',
            'password' => 'testing-password',
            'user_type' => 'staff',
        ])->save();
        $cashboxes = app(CashboxService::class);
        $cashbox = $cashboxes->createCashbox([
            'name' => 'Price List Register',
            'code' => 'PRICE-LIST-' . uniqid(),
            'currency' => 'USD',
            'status' => 'active',
        ]);
        $cashboxes->openShift($cashbox, $cashier, 0);

        $order = app(WebPosService::class)->createPosOrder(
            [['product_stock_id' => $stock->id, 'quantity' => 2]],
            ['payment_type' => 'cash', 'paid_amount' => 120, 'customer_id' => $customer->id],
            $cashier,
            'price-list-checkout-' . uniqid()
        );

        $this->assertSame(110.0, (float) $order->orderDetails->sole()->price);
        $this->assertSame(110.0, (float) $order->grand_total);
        $this->assertSame('price_list', $order->pos_metadata['pricing'][0]['snapshot']['source']);
        $this->assertSame($list->id, $order->pos_metadata['pricing'][0]['snapshot']['price_list_id']);
    }

    private function subject(string $method, array $productAttributes = []): array
    {
        $customer = $this->customer();
        $list = PriceList::query()->create([
            'name' => 'Test ' . uniqid(),
            'code' => 'TEST-' . strtoupper(uniqid()),
            'type' => 'custom',
            'pricing_method' => $method,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $customer->forceFill(['price_list_id' => $list->id])->save();
        $now = now();
        $productId = DB::table('products')->insertGetId(array_merge([
            'name' => 'Price List Product ' . uniqid(),
            'user_id' => $customer->id,
            'category_id' => 1,
            'unit_price' => 100,
            'purchase_price' => 40,
            'current_stock' => 10,
            'slug' => 'price-list-' . uniqid(),
            'created_at' => $now,
            'updated_at' => $now,
        ], $productAttributes));
        $stockId = DB::table('product_stocks')->insertGetId([
            'product_id' => $productId,
            'variant' => '',
            'sku' => 'PRICE-' . uniqid(),
            'price' => 100,
            'qty' => 10,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [ProductStock::query()->with('product')->findOrFail($stockId), $customer, $list];
    }

    private function item(PriceList $list, ProductStock $stock, array $attributes): PriceListItem
    {
        return PriceListItem::query()->create(array_merge([
            'price_list_id' => $list->id,
            'product_id' => $stock->product_id,
            'product_stock_id' => $stock->id,
            'is_active' => true,
        ], $attributes));
    }

    private function customer(): User
    {
        return User::query()->create([
            'name' => 'Price Customer',
            'email' => 'price-' . uniqid() . '@example.test',
            'password' => 'testing-password',
            'user_type' => 'customer',
            'banned' => 0,
        ]);
    }
}
