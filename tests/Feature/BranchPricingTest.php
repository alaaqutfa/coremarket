<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductBranchPrice;
use App\Models\ProductStock;
use App\Models\StoreBranch;
use App\Models\User;
use App\Services\CashboxService;
use App\Services\CoreMarketPriceListService;
use App\Services\CoreMarketBranchPricingService;
use App\Services\CoreMarketStorefrontPriceDisplayService;
use App\Services\OperationsPdfService;
use App\Services\WebPosService;
use App\Utility\CartUtility;
use Database\Seeders\DocumentTemplateSeeder;
use Database\Seeders\StaffRolePresetSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BranchPricingTest extends TestCase
{
    use DatabaseTransactions;

    private StoreBranch $main;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('coremarket_testing', DB::getDatabaseName());
        $this->assertTrue(Schema::hasTable('product_branch_prices'));
        $this->main = StoreBranch::query()->where('is_default', true)->firstOrFail();
        $this->setting('pricing.price_lists_enabled', false);
        $this->setting('pricing.branch_pricing_enabled', true);
        $this->setting('pricing.branch_pricing_priority', 'branch_price_first');
    }

    protected function tearDown(): void
    {
        Cache::forget('business_settings');
        parent::tearDown();
    }

    public function test_disabled_feature_keeps_existing_public_and_sale_behavior(): void
    {
        $stock = $this->stock(['discount' => 10, 'discount_type' => 'percent']);
        $this->branchPrice($stock, $this->main, 60);
        $this->setting('pricing.branch_pricing_enabled', false);

        $snapshot = app(CoreMarketPriceListService::class)->pricingSnapshot($stock);

        $this->assertSame('sale_price', $snapshot['source']);
        $this->assertSame(90.0, $snapshot['resolved_price']);
        $this->assertNull($snapshot['branch_price']);
    }

    public function test_branch_price_and_fallback_are_request_scoped_without_leak(): void
    {
        $stock = $this->stock();
        $second = $this->branch('NORTH');
        $this->branchPrice($stock, $this->main, 80);
        $this->branchPrice($stock, $second, 70);
        $prices = app(CoreMarketPriceListService::class);

        $main = $prices->pricingSnapshot($stock, null, ['branch' => $this->main]);
        $north = $prices->pricingSnapshot($stock, null, ['branch' => $second]);
        $missing = $this->branch('MISSING');
        $fallback = $prices->pricingSnapshot($stock, null, ['branch' => $missing]);

        $this->assertSame(80.0, $main['resolved_price']);
        $this->assertSame($this->main->id, $main['branch_id']);
        $this->assertSame(70.0, $north['resolved_price']);
        $this->assertSame($second->id, $north['branch_id']);
        $this->assertSame('regular_price', $fallback['source']);
        $this->assertSame(100.0, $fallback['resolved_price']);
    }

    public function test_branch_customer_sale_and_lowest_priorities_remain_separate(): void
    {
        $stock = $this->stock(['discount' => 10, 'discount_type' => 'percent']);
        $customer = $this->customer();
        $list = PriceList::query()->create([
            'name' => 'Branch Priority Customer',
            'code' => 'BRANCH-'.strtoupper(uniqid()),
            'type' => 'custom',
            'pricing_method' => 'fixed_price',
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $customer->forceFill(['price_list_id' => $list->id])->save();
        PriceListItem::query()->create([
            'price_list_id' => $list->id,
            'product_id' => $stock->product_id,
            'product_stock_id' => $stock->id,
            'fixed_price' => 70,
            'is_active' => true,
        ]);
        $this->branchPrice($stock, $this->main, 80);
        $this->setting('pricing.price_lists_enabled', true);
        $prices = app(CoreMarketPriceListService::class);

        $branch = $prices->pricingSnapshot($stock, $customer, ['branch' => $this->main, 'priority' => 'branch_price_first']);
        $customerFirst = $prices->pricingSnapshot($stock, $customer, ['branch' => $this->main, 'priority' => 'customer_price_first']);
        $sale = $prices->pricingSnapshot($stock, $customer, ['branch' => $this->main, 'priority' => 'sale_price_first']);
        $lowest = $prices->pricingSnapshot($stock, $customer, ['branch' => $this->main, 'priority' => 'lowest_price']);

        $this->assertSame(['branch_price', 80.0], [$branch['source'], $branch['resolved_price']]);
        $this->assertSame(['price_list', 70.0], [$customerFirst['source'], $customerFirst['resolved_price']]);
        $this->assertSame(['sale_price', 90.0], [$sale['source'], $sale['resolved_price']]);
        $this->assertSame(['price_list', 70.0], [$lowest['source'], $lowest['resolved_price']]);
        $this->assertSame(90.0, $branch['sale_price']);
        $this->assertSame(80.0, $branch['branch_price']);
    }

    public function test_expired_or_inactive_branch_price_falls_back_safely(): void
    {
        $stock = $this->stock();
        $expired = $this->branchPrice($stock, $this->main, 50, [
            'ends_at' => now()->subMinute(),
        ]);
        $prices = app(CoreMarketPriceListService::class);
        $this->assertSame(100.0, $prices->resolvePrice($stock, null, ['branch' => $this->main]));

        $expired->update(['ends_at' => null, 'is_active' => false]);
        $this->assertSame(100.0, $prices->resolvePrice($stock, null, ['branch' => $this->main]));
    }

    public function test_pos_search_and_checkout_recalculate_for_cashier_branch(): void
    {
        $cashier = $this->staff('branch-cashier');
        $second = $this->branch('POS-BRANCH');
        $cashier->branches()->sync([$second->id => ['is_primary' => true]]);
        $stock = $this->stock(['qty' => 10]);
        $this->branchPrice($stock, $this->main, 90);
        $this->branchPrice($stock, $second, 65);
        $pos = app(WebPosService::class);

        $search = $pos->searchProducts($stock->sku, null, $cashier)->first();
        $this->assertSame(65.0, $search['price']);
        $this->assertSame($second->id, $search['pricing']['branch_id']);

        $cashboxes = app(CashboxService::class);
        $cashbox = $cashboxes->createCashbox([
            'name' => 'Branch Price Register',
            'code' => 'BPR-'.uniqid(),
            'currency' => 'USD',
            'status' => 'active',
        ]);
        $cashboxes->openShift($cashbox, $cashier, 0);
        $order = $pos->createPosOrder(
            [['product_stock_id' => $stock->id, 'quantity' => 2, 'price' => 1]],
            ['payment_type' => 'cash', 'paid_amount' => 200, 'branch_id' => $second->id],
            $cashier,
            'branch-pricing-'.uniqid()
        );

        $this->assertSame(130.0, (float) $order->orderDetails->sole()->price);
        $this->assertSame('branch_price', $order->pos_metadata['pricing'][0]['snapshot']['source']);
        $this->assertSame($second->id, $order->pos_metadata['pricing'][0]['snapshot']['branch_id']);
    }

    public function test_web_cart_uses_safe_default_branch_price(): void
    {
        $customer = $this->customer();
        $stock = $this->stock();
        $this->branchPrice($stock, $this->main, 72.345);
        $this->actingAs($customer);
        $cart = ['variation' => '', 'quantity' => 1];

        $this->assertSame(72.35, CartUtility::get_price($stock->product, $stock, 1));
        $this->assertSame(72.35, cart_product_price($cart, $stock->product, false, false));
    }

    public function test_storefront_display_resolves_per_branch_without_shared_price_state(): void
    {
        $stock = $this->stock();
        $second = $this->branch('STOREFRONT');
        $this->branchPrice($stock, $this->main, 85);
        $this->branchPrice($stock, $second, 75);
        $display = app(CoreMarketStorefrontPriceDisplayService::class);

        $main = $display->display($stock, null, ['branch' => $this->main]);
        $other = $display->display($stock, null, ['branch' => $second]);
        $guestAgain = $display->display($stock);

        $this->assertSame(85.0, $main['display_price']);
        $this->assertSame(75.0, $other['display_price']);
        $this->assertSame(85.0, $guestAgain['display_price']);
        $this->assertSame('branch_price', $guestAgain['price_source']);
    }

    public function test_roles_enforce_view_and_manage_boundaries(): void
    {
        $this->seed(StaffRolePresetSeeder::class);
        $accountant = $this->staff('branch-accountant');
        $accountant->assignRole('accountant');
        $cashier = $this->staff('branch-price-cashier');
        $cashier->assignRole('cashier');
        $this->assertTrue(app(CoreMarketBranchPricingService::class)->branchPricingEnabled());
        $this->assertTrue($accountant->can('pricing.branch_prices.view'));
        $this->assertFalse($accountant->can('pricing.branch_prices.manage'));
        $this->assertFalse($cashier->can('pricing.branch_prices.view'));
        $this->actingAs($accountant)
            ->get(route('operations.branch-prices.index'))
            ->assertOk();
        $this->actingAs($accountant)
            ->get(route('operations.branch-prices.create'))
            ->assertForbidden();
        $this->actingAs($cashier)
            ->get(route('operations.branch-prices.index'))
            ->assertForbidden();
    }

    public function test_sales_invoice_uses_stored_order_price_not_current_branch_price(): void
    {
        $this->seed(DocumentTemplateSeeder::class);
        $customer = $this->customer();
        $stock = $this->stock();
        $this->branchPrice($stock, $this->main, 40);
        $order = new Order();
        $order->forceFill([
            'user_id' => $customer->id,
            'code' => 'INV-'.uniqid(),
            'date' => now()->timestamp,
            'grand_total' => 150,
            'payment_status' => 'unpaid',
            'payment_type' => 'cash_on_delivery',
        ])->save();
        DB::table('order_details')->insert([
            'order_id' => $order->id,
            'seller_id' => $stock->product->user_id,
            'product_id' => $stock->product_id,
            'variation' => '',
            'price' => 150,
            'tax' => 0,
            'shipping_cost' => 0,
            'quantity' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        ProductBranchPrice::query()->where('product_stock_id', $stock->id)->update(['price' => 10]);

        $invoice = app(OperationsPdfService::class)->salesInvoice($order->fresh());

        $this->assertSame(75.0, $invoice['rows']->sole()['unit_price']);
        $this->assertSame(150.0, $invoice['totals']['total']);
    }

    private function stock(array $attributes = []): ProductStock
    {
        $owner = $this->staff('product-owner');
        $now = now();
        $productId = DB::table('products')->insertGetId(array_merge([
            'name' => 'Branch Price Product '.uniqid(),
            'user_id' => $owner->id,
            'category_id' => 1,
            'unit_price' => 100,
            'purchase_price' => 40,
            'current_stock' => 10,
            'slug' => 'branch-price-'.uniqid(),
            'created_at' => $now,
            'updated_at' => $now,
        ], collect($attributes)->except(['qty'])->all()));
        $stockId = DB::table('product_stocks')->insertGetId([
            'product_id' => $productId,
            'variant' => '',
            'sku' => 'BP-'.uniqid(),
            'barcode' => 'BPB-'.uniqid(),
            'price' => 100,
            'qty' => $attributes['qty'] ?? 10,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ProductStock::query()->with('product.taxes')->findOrFail($stockId);
    }

    private function branchPrice(
        ProductStock $stock,
        StoreBranch $branch,
        float $price,
        array $attributes = []
    ): ProductBranchPrice {
        return ProductBranchPrice::query()->create(array_merge([
            'store_branch_id' => $branch->id,
            'product_id' => $stock->product_id,
            'product_stock_id' => $stock->id,
            'price' => $price,
            'is_active' => true,
        ], $attributes));
    }

    private function branch(string $code): StoreBranch
    {
        return StoreBranch::query()->create([
            'name' => $code,
            'code' => $code.'-'.uniqid(),
            'is_default' => false,
            'is_active' => true,
        ]);
    }

    private function customer(): User
    {
        return User::query()->create([
            'name' => 'Branch Price Customer',
            'email' => 'branch-customer-'.uniqid().'@example.test',
            'password' => bcrypt('Temporary123!'),
            'user_type' => 'customer',
            'banned' => 0,
        ]);
    }

    private function staff(string $prefix): User
    {
        $user = new User();
        $user->forceFill([
            'name' => $prefix,
            'email' => $prefix.'-'.uniqid().'@example.test',
            'password' => bcrypt('Temporary123!'),
            'user_type' => 'staff',
            'banned' => 0,
        ])->save();

        return $user;
    }

    private function setting(string $key, bool|string $value): void
    {
        DB::table('business_settings')->updateOrInsert(
            ['type' => $key, 'lang' => null],
            ['value' => is_bool($value) ? ($value ? '1' : '0') : $value]
        );
        Cache::forget('business_settings');
    }
}
