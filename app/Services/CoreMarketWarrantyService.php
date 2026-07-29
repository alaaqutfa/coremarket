<?php

namespace App\Services;

use App\Models\ProductSerialUnit;
use App\Models\ProductWarrantyPolicy;
use App\Models\User;
use App\Models\WarrantyClaim;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

class CoreMarketWarrantyService
{
    public const STATUSES = ['received', 'under_review', 'sent_to_supplier', 'repaired', 'replaced', 'rejected', 'returned_to_customer', 'closed'];

    public function warrantyTrackingEnabled(): bool
    {
        return filter_var(
            get_setting('inventory.warranty_tracking_enabled', config('coremarket.inventory.warranty_tracking_enabled', false)),
            FILTER_VALIDATE_BOOL
        );
    }

    public function resolvePolicy(int $productId, ?int $productStockId = null): ?ProductWarrantyPolicy
    {
        if (! $this->warrantyTrackingEnabled()) {
            return null;
        }

        return ProductWarrantyPolicy::query()
            ->where('status', 'active')
            ->where(function ($query) use ($productId, $productStockId) {
                if ($productStockId) {
                    $query->where('product_stock_id', $productStockId);
                }
                $query->orWhere(fn ($q) => $q->where('product_id', $productId)->whereNull('product_stock_id'));
            })
            ->orderByRaw('product_stock_id IS NULL')
            ->first();
    }

    public function warrantyExpiryForSale(int $productId, ?int $productStockId, mixed $soldAt): ?CarbonImmutable
    {
        $policy = $this->resolvePolicy($productId, $productStockId);
        return $policy && $policy->warranty_months > 0
            ? CarbonImmutable::parse($soldAt ?: now())->addMonthsNoOverflow($policy->warranty_months)
            : null;
    }

    public function createClaim(array $payload, User $actor): WarrantyClaim
    {
        if (! $this->warrantyTrackingEnabled()) {
            throw new DomainException('Warranty tracking is disabled.');
        }

        return DB::transaction(function () use ($payload, $actor) {
            $unit = isset($payload['product_serial_unit_id'])
                ? ProductSerialUnit::query()->lockForUpdate()->findOrFail($payload['product_serial_unit_id'])
                : null;
            if ($unit && $unit->warranty_expires_at && $unit->warranty_expires_at->isPast()) {
                throw new DomainException('The serialized unit warranty has expired.');
            }

            if ($unit) {
                $unit->forceFill(['status' => 'warranty_claim'])->save();
            }

            return WarrantyClaim::query()->create([
                'customer_id' => $payload['customer_id'] ?? $unit?->order?->user_id,
                'order_id' => $payload['order_id'] ?? $unit?->order_id,
                'order_detail_id' => $payload['order_detail_id'] ?? $unit?->order_detail_id,
                'product_serial_unit_id' => $unit?->id,
                'product_id' => $payload['product_id'] ?? $unit?->product_id,
                'product_stock_id' => $payload['product_stock_id'] ?? $unit?->product_stock_id,
                'status' => 'received',
                'issue_description' => $payload['issue_description'] ?? null,
                'received_by_user_id' => $actor->id,
                'received_at' => now(),
                'metadata' => ['stock_unchanged' => true],
            ]);
        });
    }

    public function updateClaimStatus(WarrantyClaim $claim, string $status, User $actor, ?string $notes = null): WarrantyClaim
    {
        if (! in_array($status, self::STATUSES, true)) {
            throw new DomainException('Warranty claim status is invalid.');
        }

        return DB::transaction(function () use ($claim, $status, $actor, $notes) {
            $claim = WarrantyClaim::query()->lockForUpdate()->findOrFail($claim->id);
            if ($claim->status === 'closed') {
                throw new DomainException('A closed warranty claim cannot be changed.');
            }
            $claim->forceFill([
                'status' => $status,
                'resolution_notes' => $notes ?? $claim->resolution_notes,
                'closed_by_user_id' => $status === 'closed' ? $actor->id : $claim->closed_by_user_id,
                'closed_at' => $status === 'closed' ? now() : $claim->closed_at,
            ])->save();

            return $claim->fresh();
        });
    }

    public function claimSnapshot(ProductSerialUnit $unit): array
    {
        $policy = $this->resolvePolicy($unit->product_id, $unit->product_stock_id);
        return [
            'policy' => $policy,
            'expires_at' => $unit->warranty_expires_at,
            'is_active' => $unit->warranty_expires_at ? $unit->warranty_expires_at->isFuture() : false,
            'stock_unchanged_by_claim' => true,
        ];
    }
}
