<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\PurchaseReceipt;
use App\Models\Supplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PurchasingUiService
{
    public function suppliers(array $filters): LengthAwarePaginator
    {
        return Supplier::query()
            ->withCount('purchaseOrders')
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($supplier) use ($search) {
                    $supplier->where('name', 'like', "%{$search}%")
                        ->orWhere('company_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when(($filters['status'] ?? null) !== null && ($filters['status'] ?? null) !== '', fn ($query) => $query->where('is_active', $filters['status'] === 'active'))
            ->latest()
            ->paginate(25)
            ->withQueryString();
    }

    public function purchaseOrders(array $filters): LengthAwarePaginator
    {
        return PurchaseOrder::query()
            ->with(['supplier', 'items'])
            ->when($filters['supplier_id'] ?? null, fn ($query, $supplierId) => $query->where('supplier_id', $supplierId))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('ordered_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('ordered_at', '<=', $to))
            ->latest()
            ->paginate(25)
            ->withQueryString();
    }

    public function receipts(array $filters): LengthAwarePaginator
    {
        return PurchaseReceipt::query()
            ->with(['purchaseOrder.supplier', 'items'])
            ->when($filters['supplier_id'] ?? null, fn ($query, $supplierId) => $query->whereHas('purchaseOrder', fn ($order) => $order->where('supplier_id', $supplierId)))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('received_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('received_at', '<=', $to))
            ->latest('received_at')
            ->paginate(25)
            ->withQueryString();
    }

    public function progress(PurchaseOrder $order): array
    {
        $ordered = (float) $order->items->sum('quantity_ordered');
        $received = (float) $order->items->sum('quantity_received');

        return ['ordered' => $ordered, 'received' => $received, 'remaining' => max(0, $ordered - $received)];
    }
}
