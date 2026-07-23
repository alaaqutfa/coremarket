<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Services\PurchaseReceivingService;
use App\Services\PurchaseReturnService;
use App\Services\SupplierLedgerService;
use App\Services\CoreMarketInventoryPolicyService;
use App\Models\BusinessSetting;
use Illuminate\Support\Facades\Cache;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurchaseReturnServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Cache::forget('business_settings');
        parent::tearDown();
    }

    public function test_completed_purchase_return_reduces_stock_and_supplier_balance_once(): void
    {
        [$supplier, $order, $stockId] = $this->receivedPurchase();
        $service = app(PurchaseReturnService::class);
        $purchaseReturn = $service->createDraft($order, [[
            'purchase_order_item_id' => $order->items->first()->id,
            'quantity' => 2,
        ]], ['reason' => 'Damaged supplier stock']);

        $this->assertSame('draft', $purchaseReturn->status);
        $this->assertSame(16.0, (float) $purchaseReturn->subtotal);
        $this->assertSame(0.8, (float) $purchaseReturn->tax_total);
        $this->assertSame(16.8, (float) $purchaseReturn->total);

        $service->complete($purchaseReturn);
        $service->complete($purchaseReturn);

        $this->assertSame('completed', $purchaseReturn->fresh()->status);
        $this->assertSame(5.0, (float) DB::table('product_stocks')->where('id', $stockId)->value('qty'));
        $this->assertSame(5.0, (float) DB::table('products')->where('id', $order->items->first()->product_id)->value('current_stock'));
        $this->assertSame(1, DB::table('inventory_movements')->where('movement_type', 'purchase_return')->count());
        $this->assertSame(1, DB::table('supplier_ledger_entries')->where('entry_type', 'purchase_return')->count());
        $this->assertSame(25.2, app(SupplierLedgerService::class)->supplierBalance($supplier));
    }

    public function test_return_quantity_cannot_exceed_received_quantity(): void
    {
        [, $order] = $this->receivedPurchase();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Return quantity exceeds the quantity received.');
        app(PurchaseReturnService::class)->createDraft($order, [[
            'purchase_order_item_id' => $order->items->first()->id,
            'quantity' => 6,
        ]]);
    }

    public function test_cancelled_draft_releases_reserved_return_quantity(): void
    {
        [, $order] = $this->receivedPurchase();
        $service = app(PurchaseReturnService::class);
        $first = $service->createDraft($order, [[
            'purchase_order_item_id' => $order->items->first()->id,
            'quantity' => 5,
        ]]);

        $service->cancel($first);
        $replacement = $service->createDraft($order, [[
            'purchase_order_item_id' => $order->items->first()->id,
            'quantity' => 5,
        ]]);

        $this->assertSame('cancelled', $first->fresh()->status);
        $this->assertSame('draft', $replacement->status);
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_purchase_return_respects_negative_stock_policy(): void
    {
        [, $order, $stockId] = $this->receivedPurchase();
        DB::table('product_stocks')->where('id', $stockId)->update(['qty' => 0]);
        $service = app(PurchaseReturnService::class);
        $purchaseReturn = $service->createDraft($order, [[
            'purchase_order_item_id' => $order->items->first()->id,
            'quantity' => 1,
        ]]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Purchase return quantity exceeds current stock.');
        $service->complete($purchaseReturn);
    }

    public function test_purchase_return_can_reduce_below_zero_when_policy_allows_it(): void
    {
        [, $order, $stockId] = $this->receivedPurchase();
        DB::table('product_stocks')->where('id', $stockId)->update(['qty' => 0]);
        $setting = BusinessSetting::query()
            ->where('type', CoreMarketInventoryPolicyService::NEGATIVE_STOCK_SETTING)
            ->whereNull('lang')
            ->first() ?: new BusinessSetting();
        $setting->forceFill([
            'type' => CoreMarketInventoryPolicyService::NEGATIVE_STOCK_SETTING,
            'value' => '1',
            'lang' => null,
        ])->save();
        Cache::forget('business_settings');
        $service = app(PurchaseReturnService::class);
        $purchaseReturn = $service->createDraft($order, [[
            'purchase_order_item_id' => $order->items->first()->id,
            'quantity' => 1,
        ]]);

        $service->complete($purchaseReturn);

        $this->assertSame(-1.0, (float) DB::table('product_stocks')->where('id', $stockId)->value('qty'));
        $this->assertDatabaseHas('inventory_movements', [
            'movement_type' => 'purchase_return',
            'direction' => 'out',
        ]);
    }

    private function receivedPurchase(): array
    {
        $now = now();
        $supplier = Supplier::query()->create(['name' => 'Purchase Return '.uniqid(), 'is_active' => true]);
        $productId = DB::table('products')->insertGetId([
            'name' => 'Purchase Return Product',
            'user_id' => 1,
            'category_id' => 1,
            'unit_price' => 20,
            'purchase_price' => 8,
            'current_stock' => 2,
            'slug' => 'purchase-return-'.uniqid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $stockId = DB::table('product_stocks')->insertGetId([
            'product_id' => $productId,
            'variant' => '',
            'sku' => 'RETURN-'.uniqid(),
            'price' => 20,
            'qty' => 2,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $receiving = app(PurchaseReceivingService::class);
        $order = $receiving->createPurchaseOrder([
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'status' => 'ordered',
        ], [[
            'product_id' => $productId,
            'product_stock_id' => $stockId,
            'quantity_ordered' => 5,
            'unit_cost' => 8,
            'tax_amount' => 2,
        ]]);
        $receiving->receive($order, [[
            'purchase_order_item_id' => $order->items->first()->id,
            'quantity_received' => 5,
        ]], ['receipt_key' => 'purchase-return-receipt-'.uniqid()]);

        return [$supplier, $order->fresh('items'), $stockId];
    }
}
