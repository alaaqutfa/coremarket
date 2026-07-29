<?php

namespace App\Services;

use App\Models\ProductStock;
use App\Models\User;
use DomainException;

class CoreMarketInventoryGovernanceService
{
    public const DOCUMENTED_SOURCES = [
        'purchase_receipt',
        'sale',
        'sales_return',
        'purchase_return',
        'opening_stock',
        'stock_adjustment',
        'stock_count_variance',
        'emergency_adjustment',
    ];

    public function __construct(
        private CoreMarketInventoryPolicyService $policy,
        private CoreMarketBranchInventoryService $branchInventory
    ) {
    }

    public function canDirectlyEditStock(User $user): bool
    {
        return false;
    }

    public function requireDocumentedStockChange(): bool
    {
        return true;
    }

    public function currentSetupMode(): bool
    {
        return $this->policy->setupModeEnabled();
    }

    public function shouldRequireApproval(string $adjustmentType): bool
    {
        return $adjustmentType === 'emergency_adjustment'
            || $this->policy->adjustmentRequiresApproval();
    }

    public function ensureStockMutationAllowed(string $source, ProductStock $stock, float $quantityChange): void
    {
        if (! in_array($source, self::DOCUMENTED_SOURCES, true)) {
            throw new DomainException('Stock changes require an approved inventory document or an existing operational transaction.');
        }

        if ($quantityChange < 0) {
            $this->preventNegativeStockIfDisabled($stock, $quantityChange);
        } elseif ($quantityChange > 0) {
            $this->policy->assertCanIncreaseStock('authorized_adjustment');
        }
    }

    public function preventNegativeStockIfDisabled(ProductStock $stock, float $quantityChange): void
    {
        if ($quantityChange >= 0) {
            return;
        }

        $this->policy->assertCanDecreaseStock($stock, abs($quantityChange), 'manual stock adjustment');
    }

    public function assertOpeningStockAllowed(User $actor): void
    {
        if (! $this->policy->openingStockEnabled()) {
            throw new DomainException('Opening stock documents are disabled.');
        }

        if (
            ! $this->policy->setupModeEnabled()
            && $actor->user_type !== 'admin'
            && ! $actor->can('inventory.opening_stock.post')
        ) {
            throw new DomainException('Opening stock is available only during setup or to an authorized manager.');
        }
    }

    public function policySnapshot(): array
    {
        return array_merge($this->policy->policySnapshot(), [
            'documented_stock_changes_required' => true,
            'branch_inventory_scope' => $this->branchInventory->branchInventoryEnabled()
                ? 'branch'
                : 'unified',
        ]);
    }
}
