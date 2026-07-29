<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductBranchPrice;
use App\Models\ProductStock;
use App\Models\StoreBranch;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class CoreMarketBranchPricingService
{
    public const ENABLED_SETTING = 'pricing.branch_pricing_enabled';
    public const PRIORITY_SETTING = 'pricing.branch_pricing_priority';
    public const PRIORITIES = [
        'branch_price_first',
        'customer_price_first',
        'sale_price_first',
        'lowest_price',
    ];

    public function __construct(
        private CoreMarketBranchInventoryService $branches,
        private CoreMarketMoneyService $money
    ) {
    }

    public function branchPricingEnabled(): bool
    {
        return filter_var(
            get_setting(
                self::ENABLED_SETTING,
                config('coremarket.pricing.branch_pricing_enabled', false)
            ),
            FILTER_VALIDATE_BOOL
        );
    }

    public function priority(): string
    {
        $priority = (string) get_setting(
            self::PRIORITY_SETTING,
            config('coremarket.pricing.branch_pricing_priority', 'branch_price_first')
        );

        return in_array($priority, self::PRIORITIES, true)
            ? $priority
            : 'branch_price_first';
    }

    public function resolveBranchForPricing(?User $user = null, array $context = []): StoreBranch
    {
        if (($context['branch'] ?? null) instanceof StoreBranch) {
            return $context['branch'];
        }

        $actor = ($context['operator'] ?? null) instanceof User
            ? $context['operator']
            : $user;

        return $this->branches->resolveBranchForOperation(
            $context['branch_id'] ?? null,
            $actor
        );
    }

    public function getBranchPrice(
        Product|ProductStock $subject,
        StoreBranch $branch
    ): ?ProductBranchPrice {
        if (! $this->branchPricingEnabled() || ! Schema::hasTable('product_branch_prices')) {
            return null;
        }

        [$product, $stock] = $subject instanceof ProductStock
            ? [$subject->relationLoaded('product') ? $subject->product : $subject->product()->firstOrFail(), $subject]
            : [$subject, null];
        if (! $stock) {
            return null;
        }

        return ProductBranchPrice::query()
            ->where('store_branch_id', $branch->id)
            ->where('product_id', $product->id)
            ->where('product_stock_id', $stock->id)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->orderByDesc('id')
            ->first();
    }

    public function priceSnapshot(
        Product|ProductStock $subject,
        ?User $customer = null,
        array $context = []
    ): array {
        if (! $this->branchPricingEnabled()) {
            return [
                'enabled' => false,
                'branch_id' => null,
                'branch_code' => null,
                'branch_price_id' => null,
                'branch_price' => null,
            ];
        }

        $branch = $this->resolveBranchForPricing($customer, $context);
        $price = $this->getBranchPrice($subject, $branch);

        return [
            'enabled' => true,
            'branch_id' => $branch->id,
            'branch_code' => $branch->code,
            'branch_price_id' => $price?->id,
            'branch_price' => $price
                ? $this->money->normalizeMoney($price->price)
                : null,
        ];
    }
}
