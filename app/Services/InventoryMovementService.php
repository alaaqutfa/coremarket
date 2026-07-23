<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\OrderDetail;
use App\Models\ProductStock;
use App\Models\PurchaseReceiptItem;
use App\Models\PurchaseReturnItem;
use App\Models\SalesReturnItem;

class InventoryMovementService
{
    public const TYPE_SALE = 'sale';
    public const TYPE_SALE_REVERSAL = 'sale_reversal';
    public const TYPE_PURCHASE = 'purchase';
    public const TYPE_PURCHASE_RETURN = 'purchase_return';

    public function recordSale(OrderDetail $orderDetail, ?ProductStock $productStock = null, ?int $createdBy = null): InventoryMovement
    {
        $productStock = $productStock ?: $this->findProductStock($orderDetail);
        $snapshot = $this->snapshotCost($orderDetail, $productStock);

        $movement = InventoryMovement::query()->firstOrCreate(
            [
                'reference_type' => OrderDetail::class,
                'reference_id' => $orderDetail->id,
                'movement_type' => self::TYPE_SALE,
            ],
            $this->movementAttributes($orderDetail, $productStock, self::TYPE_SALE, 'out', $snapshot, $createdBy)
        );
        app(AccountingEventService::class)->recordSale($orderDetail, $createdBy);
        return $movement;
    }

    public function recordSaleReversal(OrderDetail $orderDetail, ?ProductStock $productStock = null, ?int $createdBy = null): InventoryMovement
    {
        $productStock = $productStock ?: $this->findProductStock($orderDetail);
        $snapshot = $this->snapshotCost($orderDetail, $productStock);

        return InventoryMovement::query()->firstOrCreate(
            [
                'reference_type' => OrderDetail::class,
                'reference_id' => $orderDetail->id,
                'movement_type' => self::TYPE_SALE_REVERSAL,
            ],
            $this->movementAttributes($orderDetail, $productStock, self::TYPE_SALE_REVERSAL, 'in', $snapshot, $createdBy)
        );
    }

    public function recordSalesReturnReversal(SalesReturnItem $returnItem, ?int $createdBy = null): InventoryMovement
    {
        return InventoryMovement::query()->firstOrCreate(
            [
                'reference_type' => SalesReturnItem::class,
                'reference_id' => $returnItem->id,
                'movement_type' => self::TYPE_SALE_REVERSAL,
            ],
            [
                'product_id' => $returnItem->product_id,
                'product_stock_id' => $returnItem->product_stock_id,
                'variant' => $returnItem->variant,
                'direction' => 'in',
                'quantity' => $returnItem->quantity,
                'unit_cost' => $returnItem->cost_price,
                'total_cost' => $returnItem->total_cost,
                'order_id' => $returnItem->order_id,
                'order_detail_id' => $returnItem->order_detail_id,
                'created_by' => $createdBy,
                'metadata' => [
                    'cost_source' => $returnItem->metadata['cost_source'] ?? 'missing',
                    'sales_return_id' => $returnItem->sales_return_id,
                ],
            ]
        );
    }

    public function recordPurchaseReceipt(PurchaseReceiptItem $receiptItem, ?int $createdBy = null): InventoryMovement
    {
        return InventoryMovement::query()->firstOrCreate(
            [
                'reference_type' => PurchaseReceiptItem::class,
                'reference_id' => $receiptItem->id,
                'movement_type' => self::TYPE_PURCHASE,
            ],
            [
                'product_id' => $receiptItem->product_id,
                'product_stock_id' => $receiptItem->product_stock_id,
                'direction' => 'in',
                'quantity' => $receiptItem->quantity_received,
                'unit_cost' => $receiptItem->unit_cost,
                'total_cost' => $receiptItem->total_cost,
                'reference_type' => PurchaseReceiptItem::class,
                'reference_id' => $receiptItem->id,
                'created_by' => $createdBy,
                'metadata' => [
                    'purchase_order_item_id' => $receiptItem->purchase_order_item_id,
                    'purchase_receipt_id' => $receiptItem->purchase_receipt_id,
                ],
            ]
        );
    }

    public function recordPurchaseReturn(PurchaseReturnItem $returnItem, ?int $createdBy = null): InventoryMovement
    {
        return InventoryMovement::query()->firstOrCreate(
            [
                'reference_type' => PurchaseReturnItem::class,
                'reference_id' => $returnItem->id,
                'movement_type' => self::TYPE_PURCHASE_RETURN,
            ],
            [
                'product_id' => $returnItem->product_id,
                'product_stock_id' => $returnItem->product_stock_id,
                'movement_type' => self::TYPE_PURCHASE_RETURN,
                'direction' => 'out',
                'quantity' => $returnItem->quantity,
                'unit_cost' => $returnItem->unit_cost,
                'total_cost' => (float) $returnItem->line_total - (float) $returnItem->tax_amount,
                'reference_type' => PurchaseReturnItem::class,
                'reference_id' => $returnItem->id,
                'created_by' => $createdBy,
                'metadata' => [
                    'purchase_return_id' => $returnItem->purchase_return_id,
                    'purchase_order_item_id' => $returnItem->purchase_order_item_id,
                    'cost_source' => $returnItem->metadata['cost_source'] ?? 'purchase_order_item_snapshot',
                    'tax_amount' => $returnItem->tax_amount,
                ],
            ]
        );
    }

    public function historyForProduct(int $productId)
    {
        return InventoryMovement::query()
            ->where('product_id', $productId)
            ->latest('id');
    }

    private function snapshotCost(OrderDetail $orderDetail, ?ProductStock $productStock): array
    {
        $product = $orderDetail->product;
        $unitCost = $product && is_numeric($product->purchase_price) ? (float) $product->purchase_price : null;
        $costSource = $unitCost === null ? 'missing' : 'product_purchase_price';
        $quantity = (float) $orderDetail->quantity;
        $totalCost = $unitCost === null ? null : $unitCost * $quantity;
        $profit = $totalCost === null || ! is_numeric($orderDetail->price) ? null : (float) $orderDetail->price - $totalCost;

        if ($orderDetail->cost_source === null) {
            $orderDetail->cost_price = $unitCost;
            $orderDetail->cost_source = $costSource;
            $orderDetail->total_cost = $totalCost;
            $orderDetail->profit_amount = $profit;
            $orderDetail->profit_calculated_at = now();
            $orderDetail->save();
        }

        return [
            'unit_cost' => $orderDetail->cost_price,
            'total_cost' => $orderDetail->total_cost,
            'cost_source' => $orderDetail->cost_source,
        ];
    }

    private function movementAttributes(OrderDetail $orderDetail, ?ProductStock $productStock, string $type, string $direction, array $snapshot, ?int $createdBy): array
    {
        return [
            'product_id' => $orderDetail->product_id,
            'product_stock_id' => $productStock?->id,
            'variant' => $orderDetail->variation,
            'movement_type' => $type,
            'direction' => $direction,
            'quantity' => $orderDetail->quantity,
            'unit_cost' => $snapshot['unit_cost'],
            'total_cost' => $snapshot['total_cost'],
            'reference_type' => OrderDetail::class,
            'reference_id' => $orderDetail->id,
            'order_id' => $orderDetail->order_id,
            'order_detail_id' => $orderDetail->id,
            'created_by' => $createdBy,
            'metadata' => ['cost_source' => $snapshot['cost_source']],
        ];
    }

    private function findProductStock(OrderDetail $orderDetail): ?ProductStock
    {
        return ProductStock::query()
            ->where('product_id', $orderDetail->product_id)
            ->where('variant', $orderDetail->variation ?? '')
            ->first();
    }
}
