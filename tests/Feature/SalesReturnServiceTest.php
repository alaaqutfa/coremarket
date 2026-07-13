<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\SalesReturn;
use App\Services\SalesReturnService;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SalesReturnServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_partial_return_uses_order_detail_cost_and_profit_snapshots(): void
    {
        [$order, $detail, $stockId] = $this->makeOrderDetail();

        $return = app(SalesReturnService::class)->create($order, [[
            'order_detail_id' => $detail->id,
            'quantity' => 2,
            'reason' => 'Damaged on delivery',
        ]]);

        $item = $return->items->first();

        $this->assertSame('requested', $return->status);
        $this->assertNotNull($return->return_number);
        $this->assertSame('10.000000', $item->unit_price);
        $this->assertSame('16.000000', $item->total_cost);
        $this->assertSame('4.000000', $item->profit_reversal_amount);
        $this->assertSame(3, (int) DB::table('product_stocks')->where('id', $stockId)->value('qty'));
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_completed_return_reverses_stock_once_and_records_a_return_item_movement(): void
    {
        [$order, $detail, $stockId] = $this->makeOrderDetail();
        $service = app(SalesReturnService::class);
        $return = $service->create($order, [['order_detail_id' => $detail->id, 'quantity' => 2]]);

        $completed = $service->complete($return, 1);
        $again = $service->complete($completed, 1);

        $this->assertSame('completed', $completed->status);
        $this->assertSame($completed->id, $again->id);
        $this->assertSame(5, (int) DB::table('product_stocks')->where('id', $stockId)->value('qty'));
        $this->assertSame(5, (int) DB::table('products')->where('id', $detail->product_id)->value('current_stock'));
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseHas('inventory_movements', [
            'order_detail_id' => $detail->id,
            'movement_type' => 'sale_reversal',
            'direction' => 'in',
            'quantity' => '2.000000',
        ]);
        $this->assertSame('2.000000', $completed->items->first()->stock_reversed_quantity);
    }

    public function test_multiple_partial_returns_cannot_exceed_quantity_sold(): void
    {
        [$order, $detail] = $this->makeOrderDetail();
        $service = app(SalesReturnService::class);

        $first = $service->create($order, [['order_detail_id' => $detail->id, 'quantity' => 2]]);
        $service->complete($first);
        $second = $service->create($order, [['order_detail_id' => $detail->id, 'quantity' => 3]]);
        $service->complete($second);

        $this->expectException(DomainException::class);
        $service->create($order, [['order_detail_id' => $detail->id, 'quantity' => 1]]);
    }

    public function test_missing_cost_snapshot_does_not_break_completed_return(): void
    {
        [$order, $detail, $stockId] = $this->makeOrderDetail(null);
        $service = app(SalesReturnService::class);
        $return = $service->create($order, [['order_detail_id' => $detail->id, 'quantity' => 1]]);

        $completed = $service->complete($return);

        $this->assertNull($completed->items->first()->cost_price);
        $this->assertNull($completed->items->first()->total_cost);
        $this->assertSame(4, (int) DB::table('product_stocks')->where('id', $stockId)->value('qty'));
        $this->assertDatabaseHas('inventory_movements', [
            'order_detail_id' => $detail->id,
            'movement_type' => 'sale_reversal',
        ]);
    }

    private function makeOrderDetail(?float $costPrice = 8): array
    {
        $this->assertTrue(Schema::hasTable('sales_returns'));
        $this->assertTrue(Schema::hasTable('sales_return_items'));
        $now = now();
        $productId = DB::table('products')->insertGetId([
            'name' => 'Sales Return Foundation Product',
            'user_id' => 1,
            'category_id' => 1,
            'unit_price' => 10,
            'purchase_price' => $costPrice,
            'current_stock' => 3,
            'slug' => 'sales-return-' . uniqid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $stockId = DB::table('product_stocks')->insertGetId([
            'product_id' => $productId,
            'variant' => '',
            'sku' => 'RETURN-' . uniqid(),
            'price' => 10,
            'qty' => 3,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $orderId = DB::table('orders')->insertGetId([
            'shipping_type' => 'home_delivery',
            'date' => $now->timestamp,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $detailId = DB::table('order_details')->insertGetId([
            'order_id' => $orderId,
            'product_id' => $productId,
            'variation' => '',
            'price' => 50,
            'tax' => 5,
            'shipping_cost' => 10,
            'quantity' => 5,
            'cost_price' => $costPrice,
            'cost_source' => $costPrice === null ? 'missing' : 'product_purchase_price',
            'total_cost' => $costPrice === null ? null : 40,
            'profit_amount' => $costPrice === null ? null : 10,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [Order::findOrFail($orderId), DB::table('order_details')->where('id', $detailId)->first(), $stockId];
    }
}
