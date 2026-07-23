<?php

namespace App\Services;

use App\Models\ProductStock;
use DomainException;

class CoreMarketInventoryPolicyService
{
    public const STRICT_MODE_SETTING = 'inventory.strict_inventory_mode';
    public const NEGATIVE_STOCK_SETTING = 'inventory.allow_negative_stock';

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
        return ! $this->strictInventoryMode();
    }

    public function canAdjustStockManually(): bool
    {
        return true;
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
        if ($this->canCreateOpeningStock()) {
            return $payload;
        }

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
            'can_create_opening_stock' => $this->canCreateOpeningStock(),
            'can_adjust_stock_manually' => $this->canAdjustStockManually(),
        ];
    }

    private function booleanSetting(string $key, bool $default): bool
    {
        return filter_var(get_setting($key, $default ? '1' : '0'), FILTER_VALIDATE_BOOL);
    }
}
