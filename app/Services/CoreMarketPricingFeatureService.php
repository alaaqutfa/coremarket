<?php

namespace App\Services;

use DomainException;

class CoreMarketPricingFeatureService
{
    public function priceListsEnabled(): bool
    {
        return $this->settingBool(
            'pricing.price_lists_enabled',
            (bool) config('coremarket.pricing.price_lists_enabled', false)
        );
    }

    public function flexibleSellingPriceEnabled(): bool
    {
        return $this->settingBool(
            'pricing.flexible_selling_price_enabled',
            (bool) config('coremarket.pricing.flexible_selling_price_enabled', false)
        );
    }

    public function branchPricingEnabled(): bool
    {
        return app(CoreMarketBranchPricingService::class)->branchPricingEnabled();
    }

    public function branchPricingPriority(): string
    {
        return app(CoreMarketBranchPricingService::class)->priority();
    }

    public function resolveSellingPrice(float $resolvedPrice, mixed $manualPrice = null): float
    {
        if ($manualPrice === null || $manualPrice === '') {
            return $resolvedPrice;
        }
        if (! $this->flexibleSellingPriceEnabled()) {
            throw new DomainException('Manual selling price override is disabled.');
        }
        if (! is_numeric($manualPrice) || (float) $manualPrice < 0) {
            throw new DomainException('Manual selling price must be a non-negative number.');
        }

        return round((float) $manualPrice, 2);
    }

    public function snapshot(): array
    {
        return [
            'price_lists_enabled' => $this->priceListsEnabled(),
            'flexible_selling_price_enabled' => $this->flexibleSellingPriceEnabled(),
            'branch_pricing_enabled' => $this->branchPricingEnabled(),
            'branch_pricing_priority' => $this->branchPricingPriority(),
        ];
    }

    private function settingBool(string $key, bool $default): bool
    {
        return filter_var(get_setting($key, $default), FILTER_VALIDATE_BOOL);
    }
}
