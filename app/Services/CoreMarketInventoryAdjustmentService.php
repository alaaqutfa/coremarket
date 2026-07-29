<?php

namespace App\Services;

use App\Models\InventoryAdjustmentDocument;
use App\Models\InventoryAdjustmentItem;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CoreMarketInventoryAdjustmentService
{
    public const TYPES = [
        'opening_stock',
        'stock_adjustment',
        'stock_count_variance',
        'damage',
        'loss',
        'theft',
        'internal_use',
        'correction',
        'emergency_adjustment',
        'supplier_bonus',
        'expired_goods',
        'sample',
    ];

    public function __construct(
        private CoreMarketInventoryPolicyService $policy,
        private CoreMarketInventoryGovernanceService $governance,
        private CoreMarketBranchInventoryService $branchInventory
    ) {
    }

    public function createOpeningStockDocument(array $payload, User $actor): InventoryAdjustmentDocument
    {
        $this->governance->assertOpeningStockAllowed($actor);
        $payload['adjustment_type'] = 'opening_stock';

        return $this->createAdjustmentDocument($payload, $actor);
    }

    public function createAdjustmentDocument(array $payload, User $actor): InventoryAdjustmentDocument
    {
        $type = $payload['adjustment_type'] ?? 'stock_adjustment';
        if (! in_array($type, self::TYPES, true)) {
            throw new DomainException('Inventory adjustment type is invalid.');
        }
        if ($type !== 'opening_stock' && ! $this->policy->adjustmentsEnabled()) {
            throw new DomainException('Inventory adjustments are disabled.');
        }
        if ($type === 'emergency_adjustment' && ! $this->policy->emergencyAdjustmentEnabled()) {
            throw new DomainException('Emergency adjustments are disabled.');
        }

        $items = $payload['items'] ?? [];
        if ($items === []) {
            throw new DomainException('At least one inventory item is required.');
        }
        $branch = $this->branchInventory->resolveBranchForOperation(
            $payload['branch_id'] ?? null,
            $actor
        );
        $payload['branch_id'] = $branch->id;
        $key = $payload['idempotency_key'] ?? (string) Str::uuid();
        $existing = InventoryAdjustmentDocument::query()->where('idempotency_key', $key)->first();
        if ($existing) {
            return $existing->load('items');
        }

        return DB::transaction(function () use ($payload, $actor, $type, $items, $key, $branch) {
            $document = InventoryAdjustmentDocument::query()->create([
                'adjustment_type' => $type,
                'branch_id' => $payload['branch_id'] ?? null,
                'status' => 'draft',
                'reason' => $payload['reason'] ?? null,
                'notes' => $payload['notes'] ?? null,
                'created_by' => $actor->id,
                'idempotency_key' => $key,
                'metadata' => [
                    'inventory_policy' => $this->governance->policySnapshot(),
                    'branch_inventory_enabled' => $this->branchInventory->branchInventoryEnabled(),
                ],
            ]);
            $document->update(['reference_no' => 'INV-ADJ-'.str_pad((string) $document->id, 6, '0', STR_PAD_LEFT)]);

            foreach ($items as $itemPayload) {
                $stock = ProductStock::query()->with('product')->findOrFail($itemPayload['product_stock_id']);
                $change = (float) ($itemPayload['quantity_change'] ?? 0);
                if (abs($change) < 0.000001) {
                    throw new DomainException('Inventory quantity change cannot be zero.');
                }
                if ($type === 'opening_stock' && $change < 0) {
                    throw new DomainException('Opening stock quantity must be positive.');
                }
                $cost = is_numeric($itemPayload['unit_cost'] ?? null)
                    ? (float) $itemPayload['unit_cost']
                    : (is_numeric($stock->product?->purchase_price) ? (float) $stock->product->purchase_price : null);

                $quantityBefore = $this->branchInventory->branchInventoryEnabled()
                    ? (float) $this->branchInventory->getBranchBalance($stock, $branch)->quantity
                    : (float) $stock->qty;

                $document->items()->create([
                    'product_id' => $stock->product_id,
                    'product_stock_id' => $stock->id,
                    'sku_snapshot' => $stock->sku,
                    'barcode_snapshot' => $stock->barcode ?: $stock->product?->barcode,
                    'product_name_snapshot' => $stock->product?->name,
                    'quantity_before' => $quantityBefore,
                    'quantity_change' => $change,
                    'quantity_after' => $quantityBefore + $change,
                    'unit_cost' => $cost,
                    'amount' => $cost === null ? null : abs($change) * $cost,
                    'reason' => $itemPayload['reason'] ?? $payload['reason'] ?? null,
                    'metadata' => ['variant' => $stock->variant],
                ]);
            }

            return $document->load('items');
        });
    }

    public function submitForApproval(InventoryAdjustmentDocument $document, User $actor): InventoryAdjustmentDocument
    {
        return DB::transaction(function () use ($document, $actor) {
            $locked = InventoryAdjustmentDocument::query()->lockForUpdate()->findOrFail($document->id);
            if ($locked->status !== 'draft') {
                throw new DomainException('Only draft inventory documents can be submitted.');
            }
            $locked->update([
                'status' => $this->governance->shouldRequireApproval($locked->adjustment_type) ? 'pending_approval' : 'approved',
                'metadata' => array_merge($locked->metadata ?? [], ['submitted_by' => $actor->id, 'submitted_at' => now()->toIso8601String()]),
            ]);

            return $locked->fresh('items');
        });
    }

    public function approve(InventoryAdjustmentDocument $document, User $reviewer): InventoryAdjustmentDocument
    {
        return $this->review($document, $reviewer, 'approved');
    }

    public function reject(InventoryAdjustmentDocument $document, User $reviewer, ?string $notes = null): InventoryAdjustmentDocument
    {
        return $this->review($document, $reviewer, 'rejected', $notes);
    }

    public function post(InventoryAdjustmentDocument $document, User $poster): InventoryAdjustmentDocument
    {
        return DB::transaction(function () use ($document, $poster) {
            $locked = InventoryAdjustmentDocument::query()->lockForUpdate()->with('items')->findOrFail($document->id);
            if ($locked->status === 'posted') {
                return $locked;
            }
            if ($locked->status !== 'approved') {
                throw new DomainException('Inventory document must be approved before posting.');
            }
            if ($locked->adjustment_type === 'opening_stock') {
                $this->governance->assertOpeningStockAllowed($poster);
            }
            if (
                $locked->adjustment_type === 'emergency_adjustment'
                && $poster->user_type !== 'admin'
                && ! $poster->can('inventory.adjustments.emergency')
            ) {
                throw new DomainException('Emergency adjustment permission is required.');
            }

            foreach ($locked->items as $item) {
                $this->postItem($locked, $item, $poster);
            }

            $locked->update([
                'status' => 'posted',
                'posted_by' => $poster->id,
                'posted_at' => now(),
            ]);

            return $locked->fresh(['items', 'branch']);
        });
    }

    public function cancel(InventoryAdjustmentDocument $document, User $actor): InventoryAdjustmentDocument
    {
        return DB::transaction(function () use ($document, $actor) {
            $locked = InventoryAdjustmentDocument::query()->lockForUpdate()->findOrFail($document->id);
            if (! in_array($locked->status, ['draft', 'pending_approval', 'approved'], true)) {
                throw new DomainException('This inventory document cannot be cancelled.');
            }
            $locked->update([
                'status' => 'cancelled',
                'metadata' => array_merge($locked->metadata ?? [], ['cancelled_by' => $actor->id, 'cancelled_at' => now()->toIso8601String()]),
            ]);

            return $locked;
        });
    }

    private function review(InventoryAdjustmentDocument $document, User $reviewer, string $status, ?string $notes = null): InventoryAdjustmentDocument
    {
        return DB::transaction(function () use ($document, $reviewer, $status, $notes) {
            $locked = InventoryAdjustmentDocument::query()->lockForUpdate()->findOrFail($document->id);
            if ($locked->status !== 'pending_approval') {
                throw new DomainException('Only pending inventory documents can be reviewed.');
            }
            $locked->update([
                'status' => $status,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'metadata' => array_merge($locked->metadata ?? [], ['review_notes' => $notes]),
            ]);

            return $locked->fresh('items');
        });
    }

    private function postItem(InventoryAdjustmentDocument $document, InventoryAdjustmentItem $item, User $poster): void
    {
        $stock = ProductStock::query()->lockForUpdate()->findOrFail($item->product_stock_id);
        $product = Product::query()->lockForUpdate()->findOrFail($stock->product_id);
        $branch = $this->branchInventory->resolveBranchForOperation($document->branch_id, $poster);
        $before = $this->branchInventory->branchInventoryEnabled()
            ? (float) $this->branchInventory->getBranchBalance($stock, $branch)->quantity
            : (float) $stock->qty;
        $change = (float) $item->quantity_change;
        if ($document->adjustment_type === 'opening_stock' && abs($before) > 0.000001) {
            throw new DomainException('Opening stock can be posted only when the current stock is zero.');
        }

        $source = match ($document->adjustment_type) {
            'opening_stock' => 'opening_stock',
            'stock_count_variance' => 'stock_count_variance',
            'emergency_adjustment' => 'emergency_adjustment',
            default => 'stock_adjustment',
        };
        $this->governance->ensureStockMutationAllowed($source, $stock, $change);
        $after = $before + $change;
        if ($change > 0) {
            $this->branchInventory->increaseBranchStock(
                $stock,
                $branch,
                $change,
                $source,
                ['inventory_adjustment_document_id' => $document->id]
            );
        } else {
            $this->branchInventory->decreaseBranchStock(
                $stock,
                $branch,
                abs($change),
                $source,
                ['inventory_adjustment_document_id' => $document->id]
            );
        }
        $item->update([
            'quantity_before' => $before,
            'quantity_after' => $after,
            'amount' => $item->unit_cost === null ? null : abs($change) * (float) $item->unit_cost,
        ]);

        InventoryMovement::query()->firstOrCreate(
            [
                'reference_type' => InventoryAdjustmentItem::class,
                'reference_id' => $item->id,
                'movement_type' => $source,
            ],
            [
                'product_id' => $product->id,
                'product_stock_id' => $stock->id,
                'variant' => $stock->variant,
                'direction' => $change > 0 ? 'in' : 'out',
                'quantity' => abs($change),
                'unit_cost' => $item->unit_cost,
                'total_cost' => $item->amount,
                'created_by' => $poster->id,
                'notes' => $document->notes,
                'metadata' => [
                    'document_id' => $document->id,
                    'document_reference' => $document->reference_no,
                    'adjustment_type' => $document->adjustment_type,
                    'reason' => $item->reason ?: $document->reason,
                    'before_qty' => $before,
                    'after_qty' => $after,
                    'store_branch_id' => $branch->id,
                    'branch_inventory_enabled' => $this->branchInventory->branchInventoryEnabled(),
                ],
            ]
        );
    }
}
