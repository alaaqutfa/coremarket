<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\OrderDetail;
use App\Models\ProductStock;

class InventoryMovementService
{
    public const TYPE_SALE = 'sale';
    public const TYPE_SALE_REVERSAL = 'sale_reversal';

    public function recordSale(OrderDetail $orderDetail, ?ProductStock $productStock = null, ?int $createdBy = null): InventoryMovement
    {
        $productStock = $productStock ?: $this->findProductStock($orderDetail);
        $snapshot = $this->snapshotCost($orderDetail, $productStock);

        return InventoryMovement::query()->firstOrCreate(
            ['order_detail_id' => $orderDetail->id, 'movement_type' => self::TYPE_SALE],
            $this->movementAttributes($orderDetail, $productStock, self::TYPE_SALE, 'out', $snapshot, $createdBy)
        );
    }

    public function recordSaleReversal(OrderDetail $orderDetail, ?ProductStock $productStock = null, ?int $createdBy = null): InventoryMovement
    {
        $productStock = $productStock ?: $this->findProductStock($orderDetail);
        $snapshot = $this->snapshotCost($orderDetail, $productStock);

        return InventoryMovement::query()->firstOrCreate(
            ['order_detail_id' => $orderDetail->id, 'movement_type' => self::TYPE_SALE_REVERSAL],
            $this->movementAttributes($orderDetail, $productStock, self::TYPE_SALE_REVERSAL, 'in', $snapshot, $createdBy)
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
