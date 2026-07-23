<?php

namespace App\Services;

use InvalidArgumentException;

class PurchaseItemPricingService
{
    public function __construct(
        private CoreMarketMoneyService $money,
        private CoreMarketTaxService $tax
    ) {
    }

    public function calculate(array $item, ?float $fallbackRegularPrice = null): array
    {
        $quantity = $this->positiveNumber($item['quantity_ordered'] ?? null, 'Quantity');
        $costPrice = $this->nullableMoney($item['unit_cost'] ?? null);
        $regularPrice = $this->nullableMoney($item['regular_price'] ?? null);
        $requestedMargin = $this->nullableNumber($item['margin_percent'] ?? null);
        $pricingSource = 'current_product_price';

        if ($regularPrice !== null) {
            $pricingSource = 'regular_price';
        } elseif ($requestedMargin !== null) {
            if ($costPrice === null) {
                throw new InvalidArgumentException('Cost price is required when margin percent is used.');
            }
            $regularPrice = $this->money->normalizeMoney($costPrice * (1 + ($requestedMargin / 100)));
            $pricingSource = 'margin_percent';
        } elseif ($fallbackRegularPrice !== null) {
            $regularPrice = $this->money->normalizeMoney($fallbackRegularPrice);
        }

        $marginPercent = $costPrice !== null && $costPrice > 0 && $regularPrice !== null
            ? round((($regularPrice - $costPrice) / $costPrice) * 100, 4, PHP_ROUND_HALF_UP)
            : $requestedMargin;

        $salePrice = $this->nullableMoney($item['sale_price'] ?? null);
        if ($salePrice !== null && ($regularPrice === null || $salePrice > $regularPrice)) {
            throw new InvalidArgumentException('Sale price must not exceed the regular price.');
        }

        $lineSubtotal = $this->money->normalizeMoney(($costPrice ?? 0) * $quantity);
        $discountAmount = $this->money->normalizeMoney($item['discount_amount'] ?? 0);
        if ($discountAmount > $lineSubtotal) {
            throw new InvalidArgumentException('Purchase item discount cannot exceed the line subtotal.');
        }
        $taxableAmount = $this->money->normalizeMoney($lineSubtotal - $discountAmount);

        [$taxAmount, $taxSnapshot] = $this->taxDetails($item, $taxableAmount);

        return [
            'quantity' => $quantity,
            'cost_price' => $costPrice,
            'regular_price' => $regularPrice,
            'sale_price' => $salePrice,
            'margin_percent' => $marginPercent,
            'pricing_source' => $pricingSource,
            'discount_amount' => $discountAmount,
            'tax_amount' => $taxAmount,
            'line_subtotal' => $lineSubtotal,
            'line_total' => $this->money->normalizeMoney($taxableAmount + $taxAmount),
            'pricing_snapshot' => [
                'cost_price' => $costPrice,
                'regular_price' => $regularPrice,
                'sale_price' => $salePrice,
                'margin_percent' => $marginPercent,
                'source' => $pricingSource,
            ],
            'tax_snapshot' => $taxSnapshot,
        ];
    }

    private function taxDetails(array $item, float $taxableAmount): array
    {
        if (! array_key_exists('tax_enabled', $item)) {
            $legacyAmount = $this->money->normalizeMoney($item['tax_amount'] ?? 0);

            return [$legacyAmount, [
                'rate' => null,
                'amount' => $legacyAmount,
                'taxable' => $legacyAmount > 0,
                'source' => 'legacy_manual_tax_amount',
                'base_amount' => $taxableAmount,
                'tax_rate_id' => null,
            ]];
        }

        $enabled = filter_var($item['tax_enabled'], FILTER_VALIDATE_BOOLEAN);
        if (! $enabled) {
            return [0.0, array_merge(
                $this->tax->snapshot($taxableAmount, 0, false, 'purchase_item'),
                ['tax_rate_id' => null]
            )];
        }

        $defaultRate = null;
        $rate = $this->nullableNumber($item['tax_rate'] ?? null);
        if ($rate === null) {
            $defaultRate = $this->tax->getDefaultTaxRate();
            $rate = $defaultRate ? (float) $defaultRate->rate : null;
        }
        if ($rate === null || $rate <= 0) {
            throw new InvalidArgumentException('Tax rate is required when purchase item tax is enabled.');
        }

        $snapshot = $this->tax->snapshot(
            $taxableAmount,
            $rate,
            true,
            $defaultRate ? 'default_tax_rate' : 'purchase_item'
        );
        $snapshot['tax_rate_id'] = $defaultRate?->id;

        return [$snapshot['amount'], $snapshot];
    }

    private function nullableMoney(mixed $value): ?float
    {
        return is_numeric($value) ? $this->money->normalizeMoney($value) : null;
    }

    private function nullableNumber(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function positiveNumber(mixed $value, string $label): float
    {
        if (! is_numeric($value) || (float) $value <= 0) {
            throw new InvalidArgumentException("{$label} must be greater than zero.");
        }

        return (float) $value;
    }
}
