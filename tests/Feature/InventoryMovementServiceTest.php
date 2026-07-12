<?php

namespace Tests\Feature;

use App\Models\OrderDetail;
use App\Models\ProductStock;
use App\Services\InventoryMovementService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InventoryMovementServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_sale_records_one_movement_and_cost_snapshot(): void
    {
        [$detail, $stock] = $this->makeOrderDetail(10);
        $stock->decrement('qty', $detail->quantity);

        $service = app(InventoryMovementService::class);
        $movement = $service->recordSale($detail, $stock, 1);
        $again = $service->recordSale($detail->fresh(), $stock->fresh(), 1);

        $this->assertSame($movement->id, $again->id);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseHas('inventory_movements', [
            'order_detail_id' => $detail->id,
            'movement_type' => 'sale',
            'direction' => 'out',
            'quantity' => '2.000000',
        ]);
        $this->assertSame(3, (int) $stock->fresh()->qty);

        $snapshot = $detail->fresh();
        $this->assertSame('10.000000', $snapshot->cost_price);
        $this->assertSame('product_purchase_price', $snapshot->cost_source);
        $this->assertSame('20.000000', $snapshot->total_cost);
        $this->assertSame('40.000000', $snapshot->profit_amount);
    }

    public function test_missing_cost_still_records_sale_without_breaking_the_order_detail(): void
    {
        [$detail, $stock] = $this->makeOrderDetail(null);

        app(InventoryMovementService::class)->recordSale($detail, $stock);

        $snapshot = $detail->fresh();
        $this->assertNull($snapshot->cost_price);
        $this->assertSame('missing', $snapshot->cost_source);
        $this->assertNull($snapshot->total_cost);
        $this->assertNull($snapshot->profit_amount);
        $this->assertDatabaseHas('inventory_movements', [
            'order_detail_id' => $detail->id,
            'movement_type' => 'sale',
        ]);
    }

    private function makeOrderDetail(?float $purchasePrice): array
    {
        $this->assertTrue(Schema::hasTable('inventory_movements'));
        $this->assertTrue(Schema::hasColumn('order_details', 'cost_price'));

        $now = now();
        $productId = DB::table('products')->insertGetId([
            'name' => 'Inventory Foundation Test Product',
            'user_id' => 1,
            'category_id' => 1,
            'unit_price' => 30,
            'purchase_price' => $purchasePrice,
            'slug' => 'inventory-foundation-' . uniqid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $stockId = DB::table('product_stocks')->insertGetId([
            'product_id' => $productId,
            'variant' => '',
            'sku' => 'INV-' . uniqid(),
            'price' => 30,
            'qty' => 5,
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
            'price' => 60,
            'quantity' => 2,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [OrderDetail::findOrFail($detailId), ProductStock::findOrFail($stockId)];
    }
}
