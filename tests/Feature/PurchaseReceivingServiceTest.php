<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\PurchaseReceivingService;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PurchaseReceivingServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_supplier_and_purchase_order_can_be_created_with_items(): void
    {
        [$supplier, $productId, $stockId] = $this->makeSupplierAndProduct();
        $order = app(PurchaseReceivingService::class)->createPurchaseOrder([
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'status' => 'ordered',
        ], [[
            'product_id' => $productId,
            'product_stock_id' => $stockId,
            'quantity_ordered' => 5,
            'unit_cost' => 8,
            'tax_amount' => 2,
        ]], 1);

        $this->assertNotNull($order->purchase_number);
        $this->assertSame($supplier->id, $order->supplier_id);
        $this->assertSame(1, $order->items->count());
        $this->assertSame('40.000000', $order->items->first()->total_cost);
        $this->assertSame('42.000000', $order->total_amount);
    }

    public function test_partial_receiving_updates_stock_cost_and_purchase_movement_once(): void
    {
        [, $productId, $stockId] = $this->makeSupplierAndProduct();
        $service = app(PurchaseReceivingService::class);
        $order = $this->makePurchaseOrder($service, $productId, $stockId);
        $item = $order->items->first();

        $receipt = $service->receive($order, [[
            'purchase_order_item_id' => $item->id,
            'quantity_received' => 2,
        ]], ['receipt_key' => 'receipt-' . uniqid()], 1);
        $again = $service->receive($order, [[
            'purchase_order_item_id' => $item->id,
            'quantity_received' => 2,
        ]], ['receipt_key' => $receipt->receipt_key], 1);

        $this->assertSame($receipt->id, $again->id);
        $this->assertSame('partially_received', $order->fresh()->status);
        $this->assertSame(4, (int) DB::table('product_stocks')->where('id', $stockId)->value('qty'));
        $this->assertSame(4, (int) DB::table('products')->where('id', $productId)->value('current_stock'));
        $this->assertSame(8.0, (float) DB::table('products')->where('id', $productId)->value('purchase_price'));
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseHas('inventory_movements', [
            'movement_type' => 'purchase',
            'direction' => 'in',
            'product_stock_id' => $stockId,
            'quantity' => '2.000000',
        ]);
    }

    public function test_receiving_all_quantities_marks_order_received_and_rejects_over_receiving(): void
    {
        [, $productId, $stockId] = $this->makeSupplierAndProduct();
        $service = app(PurchaseReceivingService::class);
        $order = $this->makePurchaseOrder($service, $productId, $stockId);
        $item = $order->items->first();

        $service->receive($order, [['purchase_order_item_id' => $item->id, 'quantity_received' => 5]], ['receipt_key' => 'receipt-all-' . uniqid()]);

        $this->assertSame('received', $order->fresh()->status);
        $this->expectException(DomainException::class);
        $service->receive($order, [['purchase_order_item_id' => $item->id, 'quantity_received' => 1]], ['receipt_key' => 'receipt-over-' . uniqid()]);
    }

    private function makePurchaseOrder(PurchaseReceivingService $service, int $productId, int $stockId): PurchaseOrder
    {
        return $service->createPurchaseOrder(['status' => 'ordered'], [[
            'product_id' => $productId,
            'product_stock_id' => $stockId,
            'quantity_ordered' => 5,
            'unit_cost' => 8,
        ]]);
    }

    private function makeSupplierAndProduct(): array
    {
        $this->assertTrue(Schema::hasTable('suppliers'));
        $this->assertTrue(Schema::hasTable('purchase_receipts'));
        $now = now();
        $supplier = Supplier::query()->create(['name' => 'Foundation Supplier ' . uniqid(), 'is_active' => true]);
        $productId = DB::table('products')->insertGetId([
            'name' => 'Purchase Foundation Product',
            'user_id' => 1,
            'category_id' => 1,
            'unit_price' => 20,
            'purchase_price' => 5,
            'current_stock' => 2,
            'slug' => 'purchase-foundation-' . uniqid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $stockId = DB::table('product_stocks')->insertGetId([
            'product_id' => $productId,
            'variant' => '',
            'sku' => 'PURCHASE-' . uniqid(),
            'price' => 20,
            'qty' => 2,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$supplier, $productId, $stockId];
    }
}
