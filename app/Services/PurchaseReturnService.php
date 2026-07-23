<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PurchaseReturnService
{
    public function __construct(
        private CoreMarketMoneyService $money,
        private InventoryMovementService $inventoryMovements,
        private SupplierLedgerService $ledger
    ) {
    }

    public function createDraft(PurchaseOrder $purchaseOrder, array $items, array $attributes = [], ?int $createdBy = null): PurchaseReturn
    {
        if (! $purchaseOrder->supplier_id) {
            throw new DomainException('A purchase return requires a supplier purchase order.');
        }
        if (empty($items)) {
            throw new InvalidArgumentException('A purchase return requires at least one item.');
        }

        return DB::transaction(function () use ($purchaseOrder, $items, $attributes, $createdBy) {
            $purchaseOrder = PurchaseOrder::query()->lockForUpdate()->findOrFail($purchaseOrder->id);
            $currency = strtoupper((string) ($attributes['currency'] ?? $purchaseOrder->currency ?: $this->money->baseCurrency()));
            $exchangeRate = $this->positiveRate($attributes['exchange_rate'] ?? $purchaseOrder->metadata['exchange_rate'] ?? 1);
            $return = PurchaseReturn::query()->create([
                'supplier_id' => $purchaseOrder->supplier_id,
                'purchase_order_id' => $purchaseOrder->id,
                'status' => 'draft',
                'return_date' => $attributes['return_date'] ?? today(),
                'currency' => $currency,
                'exchange_rate' => $exchangeRate,
                'reason' => $attributes['reason'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'metadata' => $attributes['metadata'] ?? null,
                'created_by' => $createdBy,
            ]);

            $subtotal = 0;
            $taxTotal = 0;
            foreach ($items as $item) {
                $orderItem = PurchaseOrderItem::query()
                    ->where('purchase_order_id', $purchaseOrder->id)
                    ->lockForUpdate()
                    ->findOrFail($item['purchase_order_item_id'] ?? 0);
                $quantity = $this->quantity($item['quantity'] ?? null);
                $this->assertReturnableQuantity($orderItem, $quantity);
                $unitCost = $this->requiredCost($orderItem->unit_cost);
                $lineSubtotal = $this->money->normalizeMoney($unitCost * $quantity);
                $taxAmount = $this->proratedTax($orderItem, $quantity);

                $return->items()->create([
                    'product_id' => $orderItem->product_id,
                    'product_stock_id' => $orderItem->product_stock_id,
                    'purchase_order_item_id' => $orderItem->id,
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'tax_amount' => $taxAmount,
                    'line_total' => $this->money->normalizeMoney($lineSubtotal + $taxAmount),
                    'metadata' => [
                        'cost_source' => 'purchase_order_item_snapshot',
                        'tax_snapshot' => $orderItem->metadata['tax_snapshot'] ?? null,
                    ],
                ]);
                $subtotal += $lineSubtotal;
                $taxTotal += $taxAmount;
            }

            $return->subtotal = $this->money->normalizeMoney($subtotal);
            $return->tax_total = $this->money->normalizeMoney($taxTotal);
            $return->total = $this->money->normalizeMoney($subtotal + $taxTotal);
            $return->total_usd = $this->money->convertByRates($return->total, $exchangeRate, 1);
            $return->return_number = 'PR-' . str_pad((string) $return->id, 8, '0', STR_PAD_LEFT);
            $return->save();

            return $return->load('items');
        });
    }

    public function complete(PurchaseReturn $purchaseReturn, ?int $completedBy = null): PurchaseReturn
    {
        return DB::transaction(function () use ($purchaseReturn, $completedBy) {
            $purchaseReturn = PurchaseReturn::query()->lockForUpdate()->findOrFail($purchaseReturn->id);
            if ($purchaseReturn->status === 'completed') {
                return $purchaseReturn->load('items');
            }
            if ($purchaseReturn->status === 'cancelled') {
                throw new DomainException('A cancelled purchase return cannot be completed.');
            }

            foreach ($purchaseReturn->items()->lockForUpdate()->get() as $item) {
                $this->returnItemToSupplier($item, $completedBy);
            }

            $purchaseReturn->status = 'completed';
            $purchaseReturn->completed_at = now();
            $purchaseReturn->completed_by = $completedBy;
            $purchaseReturn->save();
            $this->ledger->recordPurchaseReturn($purchaseReturn->load('supplier'), $completedBy);

            return $purchaseReturn->fresh('items');
        });
    }

    public function cancel(PurchaseReturn $purchaseReturn): PurchaseReturn
    {
        return DB::transaction(function () use ($purchaseReturn) {
            $purchaseReturn = PurchaseReturn::query()->lockForUpdate()->findOrFail($purchaseReturn->id);
            if ($purchaseReturn->status === 'completed') {
                throw new DomainException('A completed purchase return cannot be cancelled.');
            }
            if ($purchaseReturn->status !== 'cancelled') {
                $purchaseReturn->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            }

            return $purchaseReturn->fresh();
        });
    }

    private function assertReturnableQuantity(PurchaseOrderItem $item, float $quantity): void
    {
        $allocated = PurchaseReturnItem::query()
            ->where('purchase_order_item_id', $item->id)
            ->whereHas('purchaseReturn', fn ($query) => $query->where('status', '!=', 'cancelled'))
            ->sum('quantity');

        if ((float) $allocated + $quantity > (float) $item->quantity_received) {
            throw new DomainException('Return quantity exceeds the quantity received.');
        }
    }

    private function returnItemToSupplier(PurchaseReturnItem $item, ?int $completedBy): void
    {
        if ((float) $item->stock_returned_quantity >= (float) $item->quantity) {
            return;
        }

        $product = Product::query()->lockForUpdate()->find($item->product_id);
        $stock = ProductStock::query()->lockForUpdate()->find($item->product_stock_id);
        if (! $product || ! $stock || $product->digital) {
            throw new DomainException('A stocked product is required to complete this purchase return.');
        }
        if ((float) $stock->qty < (float) $item->quantity) {
            throw new DomainException('Purchase return quantity exceeds current stock.');
        }

        $movementExists = InventoryMovement::query()
            ->where('reference_type', PurchaseReturnItem::class)
            ->where('reference_id', $item->id)
            ->where('movement_type', InventoryMovementService::TYPE_PURCHASE_RETURN)
            ->exists();
        if (! $movementExists) {
            $stockTotalBefore = (float) ProductStock::query()->where('product_id', $product->id)->sum('qty');
            $stock->decrement('qty', $item->quantity);
            if ((float) $product->current_stock === $stockTotalBefore) {
                $product->decrement('current_stock', $item->quantity);
            }
            $movement = $this->inventoryMovements->recordPurchaseReturn($item, $completedBy);
            $item->inventory_movement_id = $movement->id;
        }

        $item->stock_returned_quantity = $item->quantity;
        $item->save();
    }

    private function proratedTax(PurchaseOrderItem $item, float $quantity): float
    {
        $ordered = (float) $item->quantity_ordered;

        return $ordered > 0
            ? $this->money->normalizeMoney((float) $item->tax_amount * ($quantity / $ordered))
            : 0.0;
    }

    private function quantity(mixed $quantity): float
    {
        if (! is_numeric($quantity) || (float) $quantity <= 0) {
            throw new InvalidArgumentException('Return quantity must be greater than zero.');
        }

        return (float) $quantity;
    }

    private function requiredCost(mixed $cost): float
    {
        if (! is_numeric($cost) || (float) $cost < 0) {
            throw new DomainException('Purchase return requires a valid purchase cost snapshot.');
        }

        return $this->money->normalizeMoney($cost);
    }

    private function positiveRate(mixed $rate): float
    {
        if (! is_numeric($rate) || (float) $rate <= 0) {
            throw new InvalidArgumentException('Exchange rate must be greater than zero.');
        }

        return (float) $rate;
    }
}
