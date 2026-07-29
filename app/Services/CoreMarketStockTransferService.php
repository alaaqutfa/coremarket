<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\ProductStock;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CoreMarketStockTransferService
{
    public function __construct(private CoreMarketBranchInventoryService $inventory)
    {
    }

    public function createTransfer(array $payload, User $actor): StockTransfer
    {
        if (! $this->inventory->branchInventoryEnabled()) {
            throw new DomainException('Branch inventory is disabled.');
        }

        $from = $this->inventory->resolveBranchForOperation($payload['from_branch_id'] ?? null, $actor);
        $to = $this->inventory->resolveBranchForOperation($payload['to_branch_id'] ?? null, $actor);
        if ($from->is($to)) {
            throw new DomainException('Source and destination branches must be different.');
        }
        if (empty($payload['items'])) {
            throw new DomainException('A stock transfer requires at least one item.');
        }

        $key = $payload['idempotency_key'] ?? (string) Str::uuid();
        $existing = StockTransfer::query()->where('idempotency_key', $key)->first();
        if ($existing) {
            return $existing->load('items');
        }

        return DB::transaction(function () use ($payload, $actor, $from, $to, $key) {
            $transfer = StockTransfer::query()->create([
                'from_branch_id' => $from->id,
                'to_branch_id' => $to->id,
                'status' => 'draft',
                'requested_by' => $actor->id,
                'notes' => $payload['notes'] ?? null,
                'idempotency_key' => $key,
                'metadata' => ['branch_inventory_enabled' => true],
            ]);
            $transfer->update([
                'reference_no' => 'STK-TRF-'.str_pad((string) $transfer->id, 6, '0', STR_PAD_LEFT),
            ]);

            foreach ($payload['items'] as $itemPayload) {
                $stock = ProductStock::query()->with('product')->findOrFail(
                    $itemPayload['product_stock_id'] ?? 0
                );
                $quantity = (float) ($itemPayload['quantity'] ?? 0);
                if ($quantity <= 0) {
                    throw new DomainException('Transfer quantity must be greater than zero.');
                }
                $transfer->items()->create([
                    'product_id' => $stock->product_id,
                    'product_stock_id' => $stock->id,
                    'sku_snapshot' => $stock->sku,
                    'barcode_snapshot' => $stock->barcode ?: $stock->product?->barcode,
                    'product_name_snapshot' => $stock->product?->name,
                    'quantity' => $quantity,
                    'unit_cost' => is_numeric($stock->product?->purchase_price)
                        ? $stock->product->purchase_price
                        : null,
                    'metadata' => ['variant' => $stock->variant],
                ]);
            }

            return $transfer->load(['items', 'fromBranch', 'toBranch']);
        });
    }

    public function submitForApproval(StockTransfer $transfer, User $actor): StockTransfer
    {
        return $this->transition($transfer, 'draft', 'pending_approval', [
            'metadata' => array_merge($transfer->metadata ?? [], [
                'submitted_by' => $actor->id,
                'submitted_at' => now()->toIso8601String(),
            ]),
        ]);
    }

    public function approve(StockTransfer $transfer, User $reviewer): StockTransfer
    {
        return $this->transition($transfer, 'pending_approval', 'approved', [
            'approved_by' => $reviewer->id,
            'approved_at' => now(),
        ]);
    }

    public function reject(StockTransfer $transfer, User $reviewer, ?string $reason): StockTransfer
    {
        return $this->transition($transfer, 'pending_approval', 'rejected', [
            'approved_by' => $reviewer->id,
            'approved_at' => now(),
            'rejection_reason' => $reason,
        ]);
    }

    public function ship(StockTransfer $transfer, User $shipper): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $shipper) {
            $locked = StockTransfer::query()->with('items')->lockForUpdate()->findOrFail($transfer->id);
            $from = $this->inventory->resolveBranchForOperation($locked->from_branch_id, $shipper);
            if ($locked->status === 'shipped' || $locked->status === 'received') {
                return $locked;
            }
            if ($locked->status !== 'approved') {
                throw new DomainException('Only an approved transfer can be shipped.');
            }

            foreach ($locked->items as $item) {
                $stock = ProductStock::query()->findOrFail($item->product_stock_id);
                $quantity = (float) $item->quantity;
                $this->inventory->decreaseBranchStock(
                    $stock,
                    $from,
                    $quantity,
                    'stock transfer shipment',
                    ['stock_transfer_id' => $locked->id]
                );
                $this->transferMovement($locked, $item, 'transfer_out', 'out', $from->id, $shipper->id);
                $item->update(['quantity_shipped' => $quantity]);
            }
            $locked->update([
                'status' => 'shipped',
                'shipped_by' => $shipper->id,
                'shipped_at' => now(),
            ]);

            return $locked->fresh(['items', 'fromBranch', 'toBranch']);
        });
    }

    public function receive(StockTransfer $transfer, User $receiver): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $receiver) {
            $locked = StockTransfer::query()->with('items')->lockForUpdate()->findOrFail($transfer->id);
            $to = $this->inventory->resolveBranchForOperation($locked->to_branch_id, $receiver);
            if ($locked->status === 'received') {
                return $locked;
            }
            if ($locked->status !== 'shipped') {
                throw new DomainException('Only a shipped transfer can be received.');
            }

            foreach ($locked->items as $item) {
                $stock = ProductStock::query()->findOrFail($item->product_stock_id);
                $quantity = (float) ($item->quantity_shipped ?? $item->quantity);
                $this->inventory->increaseBranchStock(
                    $stock,
                    $to,
                    $quantity,
                    'stock transfer receipt',
                    ['stock_transfer_id' => $locked->id]
                );
                $this->transferMovement($locked, $item, 'transfer_in', 'in', $to->id, $receiver->id);
                $item->update(['quantity_received' => $quantity]);
            }
            $locked->update([
                'status' => 'received',
                'received_by' => $receiver->id,
                'received_at' => now(),
            ]);

            return $locked->fresh(['items', 'fromBranch', 'toBranch']);
        });
    }

    public function cancel(StockTransfer $transfer, User $actor): StockTransfer
    {
        if (! in_array($transfer->status, ['draft', 'pending_approval', 'approved'], true)) {
            throw new DomainException('This transfer cannot be cancelled.');
        }

        return $this->transition($transfer, $transfer->status, 'cancelled', [
            'metadata' => array_merge($transfer->metadata ?? [], [
                'cancelled_by' => $actor->id,
                'cancelled_at' => now()->toIso8601String(),
            ]),
        ]);
    }

    private function transition(
        StockTransfer $transfer,
        string $from,
        string $to,
        array $attributes = []
    ): StockTransfer {
        return DB::transaction(function () use ($transfer, $from, $to, $attributes) {
            $locked = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if ($locked->status !== $from) {
                throw new DomainException("Stock transfer must be {$from} before this action.");
            }
            $locked->update(array_merge($attributes, ['status' => $to]));

            return $locked->fresh(['items', 'fromBranch', 'toBranch']);
        });
    }

    private function transferMovement(
        StockTransfer $transfer,
        StockTransferItem $item,
        string $type,
        string $direction,
        int $branchId,
        int $actorId
    ): InventoryMovement {
        $quantity = (float) ($type === 'transfer_in'
            ? ($item->quantity_shipped ?? $item->quantity)
            : $item->quantity);

        return InventoryMovement::query()->firstOrCreate(
            [
                'reference_type' => StockTransferItem::class,
                'reference_id' => $item->id,
                'movement_type' => $type,
            ],
            [
                'product_id' => $item->product_id,
                'product_stock_id' => $item->product_stock_id,
                'variant' => $item->metadata['variant'] ?? null,
                'direction' => $direction,
                'quantity' => $quantity,
                'unit_cost' => $item->unit_cost,
                'total_cost' => $item->unit_cost === null
                    ? null
                    : $quantity * (float) $item->unit_cost,
                'created_by' => $actorId,
                'metadata' => [
                    'stock_transfer_id' => $transfer->id,
                    'stock_transfer_reference' => $transfer->reference_no,
                    'store_branch_id' => $branchId,
                    'from_branch_id' => $transfer->from_branch_id,
                    'to_branch_id' => $transfer->to_branch_id,
                ],
            ]
        );
    }
}
