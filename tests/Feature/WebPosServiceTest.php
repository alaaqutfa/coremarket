<?php

namespace Tests\Feature;

use App\Models\CashMovement;
use App\Models\Order;
use App\Models\ProductStock;
use App\Models\User;
use App\Services\CashboxService;
use App\Services\WebPosService;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WebPosServiceTest extends TestCase
{
    use DatabaseTransactions;

    private WebPosService $service;

    private CashboxService $cashboxes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('coremarket_testing', DB::getDatabaseName());
        $this->assertTrue(Schema::hasTable('cashboxes'));
        $this->assertTrue(Schema::hasColumn('orders', 'pos_request_key'));
        $this->assertTrue(Schema::hasColumn('orders', 'cashier_shift_id'));

        $this->service = app(WebPosService::class);
        $this->cashboxes = app(CashboxService::class);
    }

    public function test_requires_an_open_cashier_shift_before_sale(): void
    {
        $user = $this->user();
        $stock = $this->productStock($user);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('An open cashier shift is required for POS sales.');

        $this->sale($stock, $user, 'no-open-shift-' . uniqid());
    }

    public function test_product_search_supports_variant_barcode_product_barcode_sku_and_name(): void
    {
        $user = $this->user();
        $stock = $this->productStock($user, [
            'product_barcode' => 'POS-PRODUCT-' . uniqid(),
            'variant_barcode' => 'POS-VARIANT-' . uniqid(),
            'sku' => 'POS-SKU-' . uniqid(),
            'name' => 'POS Searchable Coffee',
        ]);

        $variant = $this->service->searchProducts($stock->barcode)->first();
        $product = $this->service->searchProducts($stock->product->barcode)->first();
        $sku = $this->service->searchProducts($stock->sku)->first();
        $name = $this->service->searchProducts('Searchable Coffee')->first();

        $this->assertSame('variant_barcode', $variant['matched_by']);
        $this->assertSame('product_barcode', $product['matched_by']);
        $this->assertSame('sku', $sku['matched_by']);
        $this->assertSame('name', $name['matched_by']);
        $this->assertSame($stock->id, $variant['product_stock_id']);
        $this->assertSame($stock->id, $name['product_stock_id']);
    }

    public function test_creates_a_cash_pos_order_with_order_detail_cost_and_profit_snapshots(): void
    {
        $user = $this->user();
        $this->openShift($user);
        $stock = $this->productStock($user, ['price' => 20, 'purchase_price' => 10, 'qty' => 5]);

        $order = $this->sale($stock, $user, 'create-pos-' . uniqid(), 2, 50);
        $detail = $order->orderDetails->sole();

        $this->assertSame('pos', $order->order_from);
        $this->assertSame('cash', $order->payment_type);
        $this->assertSame('paid', $order->payment_status);
        $this->assertNotNull($order->cashier_shift_id);
        $this->assertNotNull($order->cashbox_id);
        $this->assertSame($user->id, $order->cashier_id);
        $this->assertSame('50.000000', $order->paid_amount);
        $this->assertSame('10.000000', $order->change_amount);
        $this->assertNotEmpty($order->pos_receipt_number);
        $this->assertNotEmpty($order->pos_request_key);
        $this->assertSame(2, (int) $detail->quantity);
        $this->assertSame(40.0, (float) $detail->price);
        $this->assertSame('10.000000', $detail->cost_price);
        $this->assertSame('20.000000', $detail->total_cost);
        $this->assertSame('20.000000', $detail->profit_amount);
    }

    public function test_deducts_stock_and_records_inventory_and_cash_sale_movements_without_a_journal(): void
    {
        $user = $this->user();
        $shift = $this->openShift($user);
        $stock = $this->productStock($user, ['qty' => 5]);
        $journalCount = DB::table('journal_entries')->count();

        $order = $this->sale($stock, $user, 'movement-pos-' . uniqid(), 2, 50);

        $this->assertSame(3, (int) $stock->fresh()->qty);
        $this->assertDatabaseHas('inventory_movements', [
            'order_id' => $order->id,
            'product_stock_id' => $stock->id,
            'movement_type' => 'sale',
            'direction' => 'out',
            'quantity' => '2.000000',
        ]);
        $this->assertDatabaseHas('cash_movements', [
            'cashier_shift_id' => $shift->id,
            'movement_type' => 'sale',
            'direction' => 'in',
            'reference_type' => Order::class,
            'reference_id' => $order->id,
            'amount' => '40.000000',
        ]);
        $this->assertSame($journalCount, DB::table('journal_entries')->count());
    }

    public function test_does_not_oversell_available_stock(): void
    {
        $user = $this->user();
        $this->openShift($user);
        $stock = $this->productStock($user, ['qty' => 1]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Requested quantity exceeds available stock.');

        $this->sale($stock, $user, 'oversell-' . uniqid(), 2, 50);
    }

    public function test_duplicate_request_key_returns_existing_order_without_duplicate_side_effects(): void
    {
        $user = $this->user();
        $this->openShift($user);
        $stock = $this->productStock($user, ['qty' => 5]);
        $key = 'idempotent-' . uniqid();

        $first = $this->sale($stock, $user, $key, 2, 50);
        $second = $this->sale($stock, $user, $key, 2, 50);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(3, (int) $stock->fresh()->qty);
        $this->assertSame(1, DB::table('order_details')->where('order_id', $first->id)->count());
        $this->assertSame(1, DB::table('inventory_movements')->where('order_id', $first->id)->where('movement_type', 'sale')->count());
        $this->assertSame(1, CashMovement::query()->where('reference_type', Order::class)->where('reference_id', $first->id)->where('movement_type', 'sale')->count());
    }

    public function test_paid_cash_must_cover_total_and_change_is_calculated(): void
    {
        $user = $this->user();
        $this->openShift($user);
        $stock = $this->productStock($user, ['price' => 20]);

        try {
            $this->sale($stock, $user, 'underpaid-' . uniqid(), 1, 19);
            $this->fail('Expected underpayment to be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame('Paid cash must cover the POS total.', $exception->getMessage());
        }

        $order = $this->sale($stock, $user, 'paid-change-' . uniqid(), 1, 25);
        $this->assertSame('5.000000', $order->change_amount);
    }

    public function test_receipt_numbers_are_unique(): void
    {
        $first = $this->service->generateReceiptNumber();
        $second = $this->service->generateReceiptNumber();

        $this->assertStringStartsWith('POS-', $first);
        $this->assertNotSame($first, $second);
    }

    public function test_closed_shift_cannot_be_used_for_a_cash_sale(): void
    {
        $user = $this->user();
        $shift = $this->openShift($user);
        $this->cashboxes->closeShift($shift, 0, null, $user);
        $stock = $this->productStock($user);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('An open cashier shift is required for POS sales.');

        $this->sale($stock, $user, 'closed-shift-' . uniqid());
    }

    public function test_inactive_cashbox_cannot_provide_a_shift_for_pos_sales(): void
    {
        $user = $this->user();
        $cashbox = $this->cashboxes->createCashbox([
            'name' => 'Inactive POS Cashbox',
            'code' => 'POS-INACTIVE-' . uniqid(),
            'status' => 'inactive',
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Cashbox is inactive.');

        $this->cashboxes->openShift($cashbox, $user, 0);
    }

    public function test_pos_sale_does_not_touch_an_existing_storefront_order(): void
    {
        $user = $this->user();
        $storefrontOrderId = DB::table('orders')->insertGetId([
            'order_from' => 'web',
            'shipping_type' => 'home_delivery',
            'payment_type' => 'cash_on_delivery',
            'payment_status' => 'unpaid',
            'date' => now()->timestamp,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->openShift($user);
        $stock = $this->productStock($user);

        $this->sale($stock, $user, 'storefront-isolated-' . uniqid());

        $this->assertDatabaseHas('orders', [
            'id' => $storefrontOrderId,
            'order_from' => 'web',
            'payment_status' => 'unpaid',
        ]);
    }

    private function sale(ProductStock $stock, User $user, string $requestKey, int $quantity = 1, float $paidAmount = 50): Order
    {
        return $this->service->createPosOrder([
            ['product_stock_id' => $stock->id, 'quantity' => $quantity],
        ], [
            'payment_type' => 'cash',
            'paid_amount' => $paidAmount,
        ], $user, $requestKey);
    }

    private function openShift(User $user)
    {
        $cashbox = $this->cashboxes->createCashbox([
            'name' => 'POS Cashbox',
            'code' => 'POS-CASH-' . uniqid(),
            'currency' => 'USD',
            'status' => 'active',
        ]);

        return $this->cashboxes->openShift($cashbox, $user, 0);
    }

    private function productStock(User $user, array $attributes = []): ProductStock
    {
        $now = now();
        $productId = DB::table('products')->insertGetId([
            'name' => $attributes['name'] ?? 'POS Test Product ' . uniqid(),
            'user_id' => $user->id,
            'category_id' => 1,
            'unit_price' => $attributes['price'] ?? 20,
            'purchase_price' => $attributes['purchase_price'] ?? 10,
            'current_stock' => $attributes['qty'] ?? 5,
            'barcode' => $attributes['product_barcode'] ?? null,
            'slug' => 'web-pos-' . uniqid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $stockId = DB::table('product_stocks')->insertGetId([
            'product_id' => $productId,
            'variant' => $attributes['variant'] ?? '',
            'sku' => $attributes['sku'] ?? 'POS-SKU-' . uniqid(),
            'barcode' => $attributes['variant_barcode'] ?? null,
            'price' => $attributes['price'] ?? 20,
            'qty' => $attributes['qty'] ?? 5,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ProductStock::query()->with('product')->findOrFail($stockId);
    }

    private function user(): User
    {
        return User::query()->create([
            'name' => 'POS Cashier',
            'email' => 'web-pos-' . uniqid() . '@example.test',
            'password' => 'testing-password',
        ]);
    }
}
