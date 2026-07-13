<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductStock;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryProService
{
    public function dashboardStats(): array
    {
        return [
            'products' => Product::count(),
            'variants' => ProductStock::count(),
            'products_with_barcode' => Product::query()->whereNotNull('barcode')->where('barcode', '!=', '')->count(),
            'variants_with_barcode' => ProductStock::query()->whereNotNull('barcode')->where('barcode', '!=', '')->count(),
            'low_stock' => $this->lowStockRows()->count(),
            'mismatches' => $this->auditSummary()['stock_mismatches'],
            'movements_today' => InventoryMovement::query()->whereDate('created_at', today())->count(),
            'movements_month' => InventoryMovement::query()->where('created_at', '>=', now()->startOfMonth())->count(),
            'latest_movements' => InventoryMovement::query()->with(['product', 'productStock'])->latest()->limit(8)->get(),
        ];
    }

    public function stockRows(array $filters = []): Collection
    {
        $rows = ProductStock::query()->with('product')->get()->map(function (ProductStock $stock) {
            $product = $stock->product;
            $threshold = (float) ($product?->low_stock_quantity ?: config('coremarket.inventory.low_stock_threshold', 5));
            $movementAt = InventoryMovement::query()->where('product_stock_id', $stock->id)->max('created_at');
            $stockTotal = (float) ProductStock::query()->where('product_id', $stock->product_id)->sum('qty');
            $status = (float) $stock->qty <= 0 ? 'out_of_stock' : ((float) $stock->qty <= $threshold ? 'low_stock' : ((float) $product?->current_stock !== $stockTotal ? 'mismatch' : 'ok'));
            return compact('stock', 'product', 'threshold', 'movementAt', 'stockTotal', 'status');
        });

        return $rows->filter(function (array $row) use ($filters) {
            $needle = strtolower(trim((string) ($filters['search'] ?? '')));
            if ($needle !== '' && ! str_contains(strtolower(($row['product']?->name ?? '').' '.$row['stock']->sku.' '.$row['stock']->barcode.' '.$row['product']?->barcode), $needle)) return false;
            if (($filters['status'] ?? '') !== '' && $row['status'] !== $filters['status']) return false;
            return empty($filters['low_stock_only']) || in_array($row['status'], ['low_stock', 'out_of_stock'], true);
        })->values();
    }

    public function lowStockRows(array $filters = []): Collection
    {
        return $this->stockRows(array_merge($filters, ['low_stock_only' => true]));
    }

    public function auditSummary(): array
    {
        $duplicates = fn (string $table, string $column) => DB::table($table)->select($column)->whereNotNull($column)->where($column, '!=', '')->groupBy($column)->havingRaw('COUNT(*) > 1')->count();
        return [
            'products_without_barcode' => Product::query()->where(fn ($q) => $q->whereNull('barcode')->orWhere('barcode', ''))->count(),
            'variants_without_barcode' => ProductStock::query()->where('variant', '!=', '')->where(fn ($q) => $q->whereNull('barcode')->orWhere('barcode', ''))->count(),
            'duplicate_product_barcodes' => $duplicates('products', 'barcode'),
            'duplicate_variant_barcodes' => $duplicates('product_stocks', 'barcode'),
            'duplicate_skus' => $duplicates('product_stocks', 'sku'),
            'stock_mismatches' => Product::query()->get()->filter(fn (Product $product) => (float) $product->current_stock !== (float) ProductStock::query()->where('product_id', $product->id)->sum('qty'))->count(),
            'movements_missing_stock' => InventoryMovement::query()->whereNotNull('product_stock_id')->whereDoesntHave('productStock')->count(),
        ];
    }

    public function adjustStock(ProductStock $productStock, array $payload, ?int $userId = null): InventoryMovement
    {
        return DB::transaction(function () use ($productStock, $payload, $userId) {
            $stock = ProductStock::query()->lockForUpdate()->findOrFail($productStock->id);
            $product = Product::query()->lockForUpdate()->findOrFail($stock->product_id);
            $quantity = (float) $payload['quantity'];
            $before = (float) $stock->qty;
            $target = match ($payload['adjustment_type']) {
                'increase' => $before + $quantity,
                'decrease' => $before - $quantity,
                'set' => $quantity,
                default => throw new DomainException('Unknown stock adjustment type.'),
            };
            if ($target < 0) throw new DomainException('Stock adjustment cannot result in negative inventory.');
            $delta = $target - $before;
            $stockTotalBefore = (float) ProductStock::query()->where('product_id', $product->id)->sum('qty');
            $stock->update(['qty' => $target]);
            if ((float) $product->current_stock === $stockTotalBefore) $product->update(['current_stock' => $stockTotalBefore + $delta]);

            return InventoryMovement::query()->create([
                'product_id' => $product->id,
                'product_stock_id' => $stock->id,
                'variant' => $stock->variant,
                'movement_type' => 'adjustment',
                'direction' => $delta > 0 ? 'in' : ($delta < 0 ? 'out' : 'neutral'),
                'quantity' => abs($delta),
                'unit_cost' => is_numeric($product->purchase_price) ? $product->purchase_price : null,
                'total_cost' => is_numeric($product->purchase_price) ? abs($delta) * (float) $product->purchase_price : null,
                'reference_type' => 'manual_adjustment',
                'created_by' => $userId,
                'notes' => $payload['notes'] ?? null,
                'metadata' => ['reason' => $payload['reason'], 'adjustment_type' => $payload['adjustment_type'], 'before_qty' => $before, 'after_qty' => $target],
            ]);
        });
    }
}
