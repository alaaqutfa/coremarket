<?php

namespace App\Services;

use App\Models\AccountingEvent;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\ProductStock;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class SalesReturnUiService
{
    public function returns(array $filters): LengthAwarePaginator
    {
        return SalesReturn::query()
            ->with(['order.user', 'items'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['return_type'] ?? null, fn ($query, $type) => $query->where('return_type', $type))
            ->when($filters['order_id'] ?? null, fn ($query, $orderId) => $query->where('order_id', $orderId))
            ->when($filters['completed'] ?? null, fn ($query, $completed) => $completed === 'yes' ? $query->whereNotNull('completed_at') : $query->whereNull('completed_at'))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('created_at', '<=', $to))
            ->latest()
            ->paginate(25)
            ->withQueryString();
    }

    public function orderReturnableRows(Order $order): array
    {
        $details = $order->orderDetails()->with('product')->get();
        $returned = SalesReturnItem::query()
            ->whereIn('order_detail_id', $details->pluck('id'))
            ->whereHas('salesReturn', fn ($query) => $query->whereNotIn('status', ['rejected', 'cancelled']))
            ->selectRaw('order_detail_id, SUM(quantity) as returned_quantity')
            ->groupBy('order_detail_id')
            ->pluck('returned_quantity', 'order_detail_id');
        $stocks = ProductStock::query()
            ->whereIn('product_id', $details->pluck('product_id')->filter()->unique())
            ->get()
            ->keyBy(fn (ProductStock $stock) => $stock->product_id.'|'.$stock->variant);

        return $details->map(function (OrderDetail $detail) use ($returned, $stocks) {
            $alreadyReturned = (float) ($returned[$detail->id] ?? 0);

            return [
                'detail' => $detail,
                'returned_quantity' => $alreadyReturned,
                'remaining_quantity' => max(0, (float) $detail->quantity - $alreadyReturned),
                'unit_price' => (float) $detail->quantity > 0 ? (float) $detail->price / (float) $detail->quantity : null,
                'unknown_cost' => $detail->cost_price === null || $detail->total_cost === null,
                'product_stock' => $stocks->get($detail->product_id.'|'.($detail->variation ?? '')),
            ];
        })->all();
    }

    public function linkedMovements(SalesReturn $salesReturn)
    {
        return InventoryMovement::query()
            ->with(['product', 'productStock'])
            ->where('movement_type', InventoryMovementService::TYPE_SALE_REVERSAL)
            ->where('reference_type', SalesReturnItem::class)
            ->whereIn('reference_id', $salesReturn->items->pluck('id'))
            ->latest()
            ->get();
    }

    public function accountingEvents(SalesReturn $salesReturn)
    {
        return AccountingEvent::query()
            ->where('event_type', 'sale_return')
            ->where('reference_type', SalesReturnItem::class)
            ->whereIn('reference_id', $salesReturn->items->pluck('id'))
            ->latest()
            ->get();
    }
}
