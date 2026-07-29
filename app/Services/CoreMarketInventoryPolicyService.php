<?php

namespace App\Services;

use App\Models\ProductStock;
use DomainException;

class CoreMarketInventoryPolicyService
{
    public const STRICT_MODE_SETTING = 'inventory.strict_inventory_mode';
    public const NEGATIVE_STOCK_SETTING = 'inventory.allow_negative_stock';
    public const SETUP_MODE_SETTING = 'inventory.setup_mode_enabled';
    public const OPENING_STOCK_SETTING = 'inventory.opening_stock_enabled';
    public const ADJUSTMENTS_SETTING = 'inventory.adjustments_enabled';
    public const ADJUSTMENT_APPROVAL_SETTING = 'inventory.adjustment_requires_approval';
    public const STOCK_COUNTS_SETTING = 'inventory.stock_counts_enabled';
    public const EMERGENCY_ADJUSTMENT_SETTING = 'inventory.emergency_adjustment_enabled';

    public function strictInventoryMode(): bool
    {
        return $this->booleanSetting(
            self::STRICT_MODE_SETTING,
            (bool) config('coremarket.inventory.strict_inventory_mode', false)
        );
    }

    public function allowNegativeStock(): bool
    {
        return $this->booleanSetting(
            self::NEGATIVE_STOCK_SETTING,
            (bool) config('coremarket.inventory.allow_negative_stock', false)
        );
    }

    public function canCreateOpeningStock(): bool
    {
        return $this->setupModeEnabled() && $this->openingStockEnabled();
    }

    public function canAdjustStockManually(): bool
    {
        return false;
    }

    public function setupModeEnabled(): bool
    {
        return $this->booleanSetting(self::SETUP_MODE_SETTING, (bool) config('coremarket.inventory.setup_mode_enabled', true));
    }

    public function openingStockEnabled(): bool
    {
        return $this->booleanSetting(self::OPENING_STOCK_SETTING, (bool) config('coremarket.inventory.opening_stock_enabled', true));
    }

    public function adjustmentsEnabled(): bool
    {
        return $this->booleanSetting(self::ADJUSTMENTS_SETTING, (bool) config('coremarket.inventory.adjustments_enabled', true));
    }

    public function adjustmentRequiresApproval(): bool
    {
        return $this->booleanSetting(self::ADJUSTMENT_APPROVAL_SETTING, (bool) config('coremarket.inventory.adjustment_requires_approval', true));
    }

    public function stockCountsEnabled(): bool
    {
        return $this->booleanSetting(self::STOCK_COUNTS_SETTING, (bool) config('coremarket.inventory.stock_counts_enabled', true));
    }

    public function emergencyAdjustmentEnabled(): bool
    {
        return $this->booleanSetting(self::EMERGENCY_ADJUSTMENT_SETTING, (bool) config('coremarket.inventory.emergency_adjustment_enabled', false));
    }

    public function assertCanDecreaseStock(ProductStock $stock, float $quantity, string $context): void
    {
        if ($quantity <= 0) {
            throw new DomainException('Stock decrease quantity must be greater than zero.');
        }

        if (! $this->allowNegativeStock() && (float) $stock->qty - $quantity < 0) {
            throw new DomainException(match ($context) {
                'POS checkout' => 'Requested quantity exceeds available stock.',
                'purchase return' => 'Purchase return quantity exceeds current stock.',
                'manual stock adjustment' => 'Stock adjustment cannot result in negative inventory.',
                default => "Insufficient stock for {$context}.",
            });
        }
    }

    public function assertCanIncreaseStock(string $sourceType): void
    {
        if (! $this->strictInventoryMode()) {
            return;
        }

        if (! in_array($sourceType, ['purchase_receipt', 'sales_return', 'authorized_adjustment'], true)) {
            throw new DomainException('Strict inventory mode requires stock increases through purchase receipts or authorized adjustments.');
        }
    }

    public function validateProductStockInput(array $payload): array
    {
        $payload['current_stock'] = 0;
        foreach (array_keys($payload) as $key) {
            if (str_starts_with((string) $key, 'qty_')) {
                $payload[$key] = 0;
            }
        }

        return $payload;
    }

    public function policySnapshot(): array
    {
        return [
            'strict_inventory_mode' => $this->strictInventoryMode(),
            'allow_negative_stock' => $this->allowNegativeStock(),
            'setup_mode_enabled' => $this->setupModeEnabled(),
            'opening_stock_enabled' => $this->openingStockEnabled(),
            'adjustments_enabled' => $this->adjustmentsEnabled(),
            'adjustment_requires_approval' => $this->adjustmentRequiresApproval(),
            'stock_counts_enabled' => $this->stockCountsEnabled(),
            'emergency_adjustment_enabled' => $this->emergencyAdjustmentEnabled(),
            'can_create_opening_stock' => $this->canCreateOpeningStock(),
            'can_adjust_stock_manually' => $this->canAdjustStockManually(),
        ];
    }

    private function booleanSetting(string $key, bool $default): bool
    {
        return filter_var(get_setting($key, $default ? '1' : '0'), FILTER_VALIDATE_BOOL);
    }
}
