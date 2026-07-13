<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptItem;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PurchaseReceivingService
{
    public function __construct(private InventoryMovementService $inventoryMovements)
    {
    }

    public function createPurchaseOrder(array $attributes, array $items, ?int $createdBy = null): PurchaseOrder
    {
        if (empty($items)) {
            throw new InvalidArgumentException('A purchase order requires at least one item.');
        }

        return DB::transaction(function () use ($attributes, $items, $createdBy) {
            $order = PurchaseOrder::query()->create([
                'supplier_id' => $attributes['supplier_id'] ?? null,
                'status' => $attributes['status'] ?? 'draft',
                'ordered_at' => $attributes['ordered_at'] ?? null,
                'currency' => $attributes['currency'] ?? null,
                'shipping_amount' => $attributes['shipping_amount'] ?? 0,
                'notes' => $attributes['notes'] ?? null,
                'created_by' => $createdBy,
                'metadata' => $attributes['metadata'] ?? null,
            ]);

            $totals = ['subtotal_amount' => 0, 'tax_amount' => 0, 'discount_amount' => 0];
            foreach ($items as $item) {
                $quantity = $this->quantity($item['quantity_ordered'] ?? null);
                $unitCost = $this->numberOrNull($item['unit_cost'] ?? null);
                $totalCost = $unitCost === null ? null : $unitCost * $quantity;
                $tax = $this->numberOrNull($item['tax_amount'] ?? null) ?? 0;
                $discount = $this->numberOrNull($item['discount_amount'] ?? null) ?? 0;
                $stock = $this->findStock($item['product_id'] ?? null, $item['product_stock_id'] ?? null, $item['variant'] ?? null);

                $order->items()->create([
                    'product_id' => $item['product_id'],
                    'product_stock_id' => $stock?->id,
                    'variant' => $item['variant'] ?? $stock?->variant,
                    'quantity_ordered' => $quantity,
                    'unit_cost' => $unitCost,
                    'tax_amount' => $tax,
                    'discount_amount' => $discount,
                    'total_cost' => $totalCost,
                    'notes' => $item['notes'] ?? null,
                    'metadata' => $item['metadata'] ?? null,
                ]);
                $totals['subtotal_amount'] += $totalCost ?? 0;
                $totals['tax_amount'] += $tax;
                $totals['discount_amount'] += $discount;
            }

            $order->purchase_number = 'PO-' . str_pad((string) $order->id, 8, '0', STR_PAD_LEFT);
            $order->fill($totals);
            $order->total_amount = $totals['subtotal_amount'] + $totals['tax_amount'] + (float) $order->shipping_amount - $totals['discount_amount'];
            $order->save();

            return $order->load('items');
        });
    }

    public function receive(PurchaseOrder $purchaseOrder, array $items, array $attributes = [], ?int $receivedBy = null): PurchaseReceipt
    {
        $receiptKey = trim((string) ($attributes['receipt_key'] ?? ''));
        if ($receiptKey === '') {
            throw new InvalidArgumentException('Receiving requires a receipt key for idempotency.');
        }
        if (empty($items)) {
            throw new InvalidArgumentException('A receipt requires at least one item.');
        }

        return DB::transaction(function () use ($purchaseOrder, $items, $attributes, $receivedBy, $receiptKey) {
            $existing = PurchaseReceipt::query()->where('receipt_key', $receiptKey)->first();
            if ($existing) {
                if ((int) $existing->purchase_order_id !== (int) $purchaseOrder->id) {
                    throw new DomainException('Receipt key belongs to another purchase order.');
                }

                return $existing->load('items');
            }

            $purchaseOrder = PurchaseOrder::query()->lockForUpdate()->findOrFail($purchaseOrder->id);
            if ($purchaseOrder->status === 'cancelled') {
                throw new DomainException('A cancelled purchase order cannot receive stock.');
            }

            $receipt = $purchaseOrder->receipts()->create([
                'receipt_key' => $receiptKey,
                'received_at' => $attributes['received_at'] ?? now(),
                'received_by' => $receivedBy,
                'notes' => $attributes['notes'] ?? null,
                'metadata' => $attributes['metadata'] ?? null,
            ]);

            foreach ($items as $item) {
                $orderItem = PurchaseOrderItem::query()
                    ->where('purchase_order_id', $purchaseOrder->id)
                    ->lockForUpdate()
                    ->findOrFail($item['purchase_order_item_id'] ?? 0);
                $quantity = $this->quantity($item['quantity_received'] ?? null);
                if ((float) $orderItem->quantity_received + $quantity > (float) $orderItem->quantity_ordered) {
                    throw new DomainException('Received quantity exceeds the quantity ordered.');
                }

                $stock = $this->lockedStock($orderItem);
                if (! $stock) {
                    throw new DomainException('A product stock record is required before receiving inventory.');
                }
                $unitCost = $this->numberOrNull($item['unit_cost'] ?? $orderItem->unit_cost);
                $receiptItem = $receipt->items()->create([
                    'purchase_order_item_id' => $orderItem->id,
                    'product_id' => $orderItem->product_id,
                    'product_stock_id' => $stock->id,
                    'quantity_received' => $quantity,
                    'unit_cost' => $unitCost,
                    'total_cost' => $unitCost === null ? null : $unitCost * $quantity,
                ]);

                $this->increaseStock($stock, $quantity, $unitCost);
                $movement = $this->inventoryMovements->recordPurchaseReceipt($receiptItem, $receivedBy);
                $receiptItem->inventory_movement_id = $movement->id;
                $receiptItem->save();

                $orderItem->quantity_received += $quantity;
                if ($unitCost !== null) {
                    $orderItem->unit_cost = $unitCost;
                }
                $orderItem->save();
            }

            $this->refreshStatus($purchaseOrder, $receivedBy);

            return $receipt->fresh('items');
        });
    }

    private function increaseStock(ProductStock $stock, float $quantity, ?float $unitCost): void
    {
        $product = Product::query()->lockForUpdate()->findOrFail($stock->product_id);
        if ($product->digital) {
            return;
        }

        $stockTotalBefore = (float) ProductStock::query()->where('product_id', $product->id)->sum('qty');
        $stock->increment('qty', $quantity);
        if ((float) $product->current_stock === $stockTotalBefore) {
            $product->increment('current_stock', $quantity);
        }

        // Keep the current legacy cost source current for future order snapshots.
        if ($unitCost !== null) {
            Product::query()->whereKey($product->id)->update(['purchase_price' => $unitCost]);
        }
    }

    private function refreshStatus(PurchaseOrder $purchaseOrder, ?int $receivedBy): void
    {
        $items = $purchaseOrder->items()->get();
        $allReceived = $items->every(fn (PurchaseOrderItem $item) => (float) $item->quantity_received >= (float) $item->quantity_ordered);
        $anyReceived = $items->contains(fn (PurchaseOrderItem $item) => (float) $item->quantity_received > 0);

        $purchaseOrder->status = $allReceived ? 'received' : ($anyReceived ? 'partially_received' : $purchaseOrder->status);
        $purchaseOrder->received_at = $allReceived ? now() : null;
        $purchaseOrder->received_by = $receivedBy;
        $purchaseOrder->save();
    }

    private function findStock(mixed $productId, mixed $stockId, ?string $variant): ?ProductStock
    {
        if ($stockId) {
            return ProductStock::query()->where('product_id', $productId)->find($stockId);
        }

        return ProductStock::query()->where('product_id', $productId)->where('variant', $variant ?? '')->first();
    }

    private function lockedStock(PurchaseOrderItem $item): ?ProductStock
    {
        $query = ProductStock::query()->where('product_id', $item->product_id)->lockForUpdate();

        if ($item->product_stock_id) {
            return $query->whereKey($item->product_stock_id)->first();
        }

        return $query->where('variant', $item->variant ?? '')->first();
    }

    private function quantity(mixed $quantity): float
    {
        if (! is_numeric($quantity) || (float) $quantity <= 0) {
            throw new InvalidArgumentException('Quantity must be greater than zero.');
        }

        return (float) $quantity;
    }

    private function numberOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
