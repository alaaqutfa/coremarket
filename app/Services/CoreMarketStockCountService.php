<?php

namespace App\Services;

use App\Models\ProductStock;
use App\Models\StockCount;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class CoreMarketStockCountService
{
    public function __construct(
        private CoreMarketInventoryPolicyService $policy,
        private CoreMarketInventoryAdjustmentService $adjustments,
        private CoreMarketBranchInventoryService $branchInventory
    ) {
    }

    public function createStockCount(array $payload, User $actor): StockCount
    {
        if (! $this->policy->stockCountsEnabled()) {
            throw new DomainException('Stock counts are disabled.');
        }
        if (empty($payload['items'])) {
            throw new DomainException('At least one stock count item is required.');
        }

        $branch = $this->branchInventory->resolveBranchForOperation(
            $payload['branch_id'] ?? null,
            $actor
        );

        return DB::transaction(function () use ($payload, $actor, $branch) {
            $count = StockCount::query()->create([
                'branch_id' => $branch->id,
                'status' => 'draft',
                'counted_by' => $actor->id,
                'counted_at' => now(),
                'notes' => $payload['notes'] ?? null,
                'metadata' => [
                    'branch_inventory_enabled' => $this->branchInventory->branchInventoryEnabled(),
                ],
            ]);
            $count->update(['reference_no' => 'STK-CNT-'.str_pad((string) $count->id, 6, '0', STR_PAD_LEFT)]);

            foreach ($payload['items'] as $itemPayload) {
                $stock = ProductStock::query()->with('product')->findOrFail($itemPayload['product_stock_id']);
                $expected = $this->branchInventory->branchInventoryEnabled()
                    ? (float) $this->branchInventory->getBranchBalance($stock, $branch)->quantity
                    : (float) $stock->qty;
                $counted = (float) $itemPayload['counted_quantity'];
                if ($counted < 0) {
                    throw new DomainException('Counted quantity cannot be negative.');
                }
                $count->items()->create([
                    'product_id' => $stock->product_id,
                    'product_stock_id' => $stock->id,
                    'sku_snapshot' => $stock->sku,
                    'barcode_snapshot' => $stock->barcode ?: $stock->product?->barcode,
                    'product_name_snapshot' => $stock->product?->name,
                    'expected_quantity' => $expected,
                    'counted_quantity' => $counted,
                    'variance_quantity' => $counted - $expected,
                    'unit_cost' => is_numeric($stock->product?->purchase_price) ? $stock->product->purchase_price : null,
                    'notes' => $itemPayload['notes'] ?? null,
                ]);
            }

            return $count->load('items');
        });
    }

    public function submitForApproval(StockCount $stockCount, User $actor): StockCount
    {
        return DB::transaction(function () use ($stockCount, $actor) {
            $locked = StockCount::query()->lockForUpdate()->findOrFail($stockCount->id);
            if ($locked->status !== 'draft') {
                throw new DomainException('Only draft stock counts can be submitted.');
            }
            $locked->update([
                'status' => $this->policy->adjustmentRequiresApproval() ? 'pending_approval' : 'approved',
                'metadata' => array_merge($locked->metadata ?? [], ['submitted_by' => $actor->id, 'submitted_at' => now()->toIso8601String()]),
            ]);

            return $locked->fresh('items');
        });
    }

    public function approve(StockCount $stockCount, User $reviewer): StockCount
    {
        return DB::transaction(function () use ($stockCount, $reviewer) {
            $locked = StockCount::query()->lockForUpdate()->findOrFail($stockCount->id);
            if ($locked->status !== 'pending_approval') {
                throw new DomainException('Only pending stock counts can be approved.');
            }
            $locked->update([
                'status' => 'approved',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ]);

            return $locked->fresh('items');
        });
    }

    public function postVarianceAsAdjustment(StockCount $stockCount, User $poster): StockCount
    {
        return DB::transaction(function () use ($stockCount, $poster) {
            $locked = StockCount::query()->lockForUpdate()->with('items')->findOrFail($stockCount->id);
            if ($locked->status === 'posted') {
                return $locked;
            }
            if ($locked->status !== 'approved') {
                throw new DomainException('Stock count must be approved before posting.');
            }

            $varianceItems = $locked->items
                ->filter(fn ($item) => abs((float) $item->variance_quantity) > 0.000001)
                ->map(fn ($item) => [
                    'product_stock_id' => $item->product_stock_id,
                    'quantity_change' => (float) $item->variance_quantity,
                    'unit_cost' => $item->unit_cost,
                    'reason' => 'Stock count variance',
                ])->values()->all();

            if ($varianceItems !== []) {
                $document = $this->adjustments->createAdjustmentDocument([
                    'adjustment_type' => 'stock_count_variance',
                    'branch_id' => $locked->branch_id,
                    'reason' => 'Variance from '.$locked->reference_no,
                    'notes' => $locked->notes,
                    'idempotency_key' => 'stock-count-'.$locked->id,
                    'items' => $varianceItems,
                ], $poster);
                if ($document->status === 'draft') {
                    $document->update([
                        'status' => 'approved',
                        'reviewed_by' => $locked->reviewed_by ?: $poster->id,
                        'reviewed_at' => $locked->reviewed_at ?: now(),
                    ]);
                }
                $this->adjustments->post($document->fresh(), $poster);
                $locked->metadata = array_merge($locked->metadata ?? [], ['adjustment_document_id' => $document->id]);
            }

            $locked->status = 'posted';
            $locked->posted_by = $poster->id;
            $locked->posted_at = now();
            $locked->save();

            return $locked->fresh('items');
        });
    }

    public function cancel(StockCount $stockCount, User $actor): StockCount
    {
        return DB::transaction(function () use ($stockCount, $actor) {
            $locked = StockCount::query()->lockForUpdate()->findOrFail($stockCount->id);
            if (! in_array($locked->status, ['draft', 'pending_approval', 'approved'], true)) {
                throw new DomainException('This stock count cannot be cancelled.');
            }
            $locked->update([
                'status' => 'cancelled',
                'metadata' => array_merge($locked->metadata ?? [], ['cancelled_by' => $actor->id]),
            ]);

            return $locked;
        });
    }
}
