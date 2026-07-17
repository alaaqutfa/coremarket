<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SalesReturnService
{
    public function __construct(private InventoryMovementService $inventoryMovements)
    {
    }

    public function create(Order $order, array $items, array $attributes = [], ?int $createdBy = null): SalesReturn
    {
        if (empty($items)) {
            throw new InvalidArgumentException('A sales return requires at least one item.');
        }

        return DB::transaction(function () use ($order, $items, $attributes, $createdBy) {
            $return = SalesReturn::query()->create([
                'order_id' => $order->id,
                'user_id' => $attributes['user_id'] ?? $order->user_id,
                'customer_id' => $attributes['customer_id'] ?? $order->user_id,
                'status' => $attributes['status'] ?? 'requested',
                'return_type' => $attributes['return_type'] ?? 'customer_return',
                'reason' => $attributes['reason'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'approved_by' => $attributes['approved_by'] ?? null,
                'created_by' => $createdBy,
                'metadata' => $attributes['metadata'] ?? null,
            ]);

            $totals = $this->emptyTotals();

            foreach ($items as $item) {
                $detail = OrderDetail::query()
                    ->where('order_id', $order->id)
                    ->lockForUpdate()
                    ->findOrFail($item['order_detail_id'] ?? 0);
                $quantity = $this->quantity($item['quantity'] ?? null);
                $this->assertReturnableQuantity($detail, $quantity);

                $returnItem = $this->makeReturnItem($return, $detail, $quantity, $item);
                $return->items()->save($returnItem);
                $totals = $this->addTotals($totals, $returnItem);
            }

            $return->fill($totals);
            $return->return_number = 'SR-' . str_pad((string) $return->id, 8, '0', STR_PAD_LEFT);
            $return->save();

            return $return->load('items');
        });
    }

    public function complete(SalesReturn $salesReturn, ?int $completedBy = null): SalesReturn
    {
        return DB::transaction(function () use ($salesReturn, $completedBy) {
            $salesReturn = SalesReturn::query()->lockForUpdate()->findOrFail($salesReturn->id);

            if ($salesReturn->status === 'completed') {
                return $salesReturn->load('items');
            }

            if (in_array($salesReturn->status, ['rejected', 'cancelled'], true)) {
                throw new DomainException('A rejected or cancelled return cannot be completed.');
            }

            $stockWasReversed = false;

            foreach ($salesReturn->items()->lockForUpdate()->get() as $returnItem) {
                $stockWasReversed = $this->reverseItemStock($returnItem, $completedBy) || $stockWasReversed;
            }

            $salesReturn->status = 'completed';
            $salesReturn->completed_at = now();
            $salesReturn->approved_by = $salesReturn->approved_by ?? $completedBy;
            $salesReturn->stock_reversed_at = $stockWasReversed ? now() : null;
            $salesReturn->save();

            $order = Order::query()->find($salesReturn->order_id);
            if ($order) {
                app(LoyaltyPointsService::class)->attemptReverseForOrder(
                    $order,
                    'Sales return completed',
                    $completedBy ? \App\Models\User::query()->find($completedBy) : null
                );
            }

            return $salesReturn->fresh('items');
        });
    }

    private function assertReturnableQuantity(OrderDetail $detail, float $quantity): void
    {
        $alreadyAllocated = SalesReturnItem::query()
            ->where('order_detail_id', $detail->id)
            ->whereHas('salesReturn', fn ($query) => $query->whereNotIn('status', ['rejected', 'cancelled']))
            ->sum('quantity');

        if (($alreadyAllocated + $quantity) > (float) $detail->quantity) {
            throw new DomainException('Return quantity exceeds the quantity sold.');
        }
    }

    private function makeReturnItem(SalesReturn $salesReturn, OrderDetail $detail, float $quantity, array $item): SalesReturnItem
    {
        $soldQuantity = (float) $detail->quantity;
        $ratio = $quantity / $soldQuantity;
        $productStock = $this->findProductStock($detail);
        $unitPrice = $this->unitPrice($detail->price, $soldQuantity);
        $taxAmount = $this->proRate($detail->tax, $ratio);
        $shippingAmount = $this->proRate($detail->shipping_cost, $ratio);
        $totalCost = $detail->total_cost !== null
            ? $this->proRate($detail->total_cost, $ratio)
            : $this->multiply($detail->cost_price, $quantity);
        $profitReversal = $detail->profit_amount !== null
            ? $this->proRate($detail->profit_amount, $ratio)
            : null;

        return new SalesReturnItem([
            'order_id' => $detail->order_id,
            'order_detail_id' => $detail->id,
            'product_id' => $detail->product_id,
            'product_stock_id' => $productStock?->id,
            'variant' => $detail->variation,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'tax_amount' => $taxAmount,
            'discount_amount' => 0,
            'cost_price' => $detail->cost_price,
            'total_cost' => $totalCost,
            'profit_reversal_amount' => $profitReversal,
            'reason' => $item['reason'] ?? $salesReturn->reason,
            'metadata' => [
                'cost_source' => $detail->cost_source ?? 'missing',
                'shipping_amount' => $shippingAmount,
            ],
        ]);
    }

    private function reverseItemStock(SalesReturnItem $returnItem, ?int $completedBy): bool
    {
        if ((float) $returnItem->stock_reversed_quantity >= (float) $returnItem->quantity) {
            return false;
        }

        $product = Product::query()->lockForUpdate()->find($returnItem->product_id);
        $stock = $this->lockedProductStock($returnItem);

        if (! $product || $product->digital || ! $stock) {
            return false;
        }

        $movementExists = InventoryMovement::query()
            ->where('reference_type', SalesReturnItem::class)
            ->where('reference_id', $returnItem->id)
            ->where('movement_type', InventoryMovementService::TYPE_SALE_REVERSAL)
            ->exists();

        if (! $movementExists) {
            $stockTotalBefore = (float) ProductStock::query()->where('product_id', $product->id)->sum('qty');
            $stock->increment('qty', $returnItem->quantity);

            // Preserve legacy behavior when current_stock is already a true stock mirror.
            if ((float) $product->current_stock === $stockTotalBefore) {
                $product->increment('current_stock', $returnItem->quantity);
            }

            $this->inventoryMovements->recordSalesReturnReversal($returnItem, $completedBy);
            app(AccountingEventService::class)->recordSalesReturn($returnItem, $completedBy);
        }

        $returnItem->stock_reversed_quantity = $returnItem->quantity;
        $returnItem->save();

        return true;
    }

    private function lockedProductStock(SalesReturnItem $returnItem): ?ProductStock
    {
        if ($returnItem->product_stock_id) {
            return ProductStock::query()->lockForUpdate()->find($returnItem->product_stock_id);
        }

        return ProductStock::query()
            ->where('product_id', $returnItem->product_id)
            ->where('variant', $returnItem->variant ?? '')
            ->lockForUpdate()
            ->first();
    }

    private function findProductStock(OrderDetail $detail): ?ProductStock
    {
        return ProductStock::query()
            ->where('product_id', $detail->product_id)
            ->where('variant', $detail->variation ?? '')
            ->first();
    }

    private function quantity(mixed $quantity): float
    {
        if (! is_numeric($quantity) || (float) $quantity <= 0) {
            throw new InvalidArgumentException('Return quantity must be greater than zero.');
        }

        return (float) $quantity;
    }

    private function emptyTotals(): array
    {
        return [
            'subtotal_amount' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 0,
            'total_cost' => 0,
            'profit_reversal_amount' => 0,
        ];
    }

    private function addTotals(array $totals, SalesReturnItem $item): array
    {
        $shipping = $item->metadata['shipping_amount'] ?? 0;
        $totals['subtotal_amount'] += (float) $item->unit_price * (float) $item->quantity;
        $totals['tax_amount'] += (float) $item->tax_amount;
        $totals['discount_amount'] += (float) $item->discount_amount;
        $totals['shipping_amount'] += (float) $shipping;
        $totals['total_cost'] += (float) $item->total_cost;
        $totals['profit_reversal_amount'] += (float) $item->profit_reversal_amount;
        $totals['total_amount'] = $totals['subtotal_amount'] + $totals['tax_amount'] + $totals['shipping_amount'] - $totals['discount_amount'];

        return $totals;
    }

    private function proRate(mixed $amount, float $ratio): ?float
    {
        return is_numeric($amount) ? (float) $amount * $ratio : null;
    }

    private function unitPrice(mixed $amount, float $quantity): ?float
    {
        return is_numeric($amount) && $quantity > 0 ? (float) $amount / $quantity : null;
    }

    private function multiply(mixed $amount, float $quantity): ?float
    {
        return is_numeric($amount) ? (float) $amount * $quantity : null;
    }
}
