<?php

namespace App\Services;

use App\Models\Product;
use InvalidArgumentException;

class CoreMarketProductPricingService
{
    public function __construct(private PurchaseItemPricingService $purchasePricing)
    {
    }

    public function normalize(array $payload, ?Product $product = null): array
    {
        $cost = $payload['cost_price']
            ?? $payload['purchase_price']
            ?? $payload['wholesale_price']
            ?? $product?->purchase_price
            ?? $product?->wholesale_price;
        $regular = $payload['regular_price']
            ?? $payload['unit_price']
            ?? $product?->unit_price;
        $sale = array_key_exists('sale_price', $payload) && $payload['sale_price'] !== '' && $payload['sale_price'] !== null
            ? $payload['sale_price']
            : ($this->salePriceFromPayload($payload, $regular) ?? $this->configuredSalePrice($product));

        $pricing = $this->purchasePricing->calculate([
            'quantity_ordered' => 1,
            'unit_cost' => $cost,
            'regular_price' => $regular,
            'margin_percent' => $payload['margin_percent'] ?? null,
            'sale_price' => $sale,
            'tax_enabled' => false,
        ], is_numeric($regular) ? (float) $regular : null);

        if ($pricing['regular_price'] === null || $pricing['regular_price'] <= 0) {
            throw new InvalidArgumentException('Regular price must be greater than zero.');
        }

        return $pricing;
    }

    public function productFields(array $pricing): array
    {
        $regular = (float) $pricing['regular_price'];
        $sale = $pricing['sale_price'];

        return [
            'purchase_price' => (float) ($pricing['cost_price'] ?? 0),
            'wholesale_price' => (float) ($pricing['cost_price'] ?? 0),
            'unit_price' => $regular,
            'discount' => $sale === null ? 0 : round($regular - (float) $sale, 2),
            'discount_type' => 'amount',
        ];
    }

    public function configuredSalePrice(?Product $product): ?float
    {
        if (! $product || ! is_numeric($product->discount) || (float) $product->discount <= 0) {
            return null;
        }

        $regular = (float) $product->unit_price;
        $sale = $product->discount_type === 'percent'
            ? $regular * (1 - ((float) $product->discount / 100))
            : $regular - (float) $product->discount;

        return round(max(0, $sale), 2);
    }

    private function salePriceFromPayload(array $payload, mixed $regular): ?float
    {
        if (! is_numeric($regular) || ! is_numeric($payload['discount'] ?? null) || (float) $payload['discount'] <= 0) {
            return null;
        }

        $regular = (float) $regular;
        $discount = (float) $payload['discount'];

        return round(max(0, ($payload['discount_type'] ?? 'amount') === 'percent'
            ? $regular * (1 - ($discount / 100))
            : $regular - $discount), 2);
    }
}
