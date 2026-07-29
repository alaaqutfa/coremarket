<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;

class CoreMarketStorefrontPriceDisplayService
{
    public function __construct(
        private CoreMarketPriceListService $prices,
        private CoreMarketMoneyService $money
    ) {
    }

    public function display(
        Product|ProductStock $subject,
        ?User $customer = null,
        array $context = []
    ): array {
        [$product, $stock] = $subject instanceof ProductStock
            ? [$subject->relationLoaded('product') ? $subject->product : $subject->product()->firstOrFail(), $subject]
            : [$subject, $context['product_stock'] ?? null];
        if (! $stock && ! $product->variant_product) {
            $stock = $product->stocks->first();
        }
        $pricingSubject = $stock ?: $subject;

        // Product records may be cached, but the resolved price must stay request/customer scoped.
        $snapshot = $this->prices->pricingSnapshot(
            $pricingSubject,
            $this->eligibleCustomer($customer),
            $context
        );
        $regular = $this->withTax($product, $snapshot['base_regular_price']);
        $sale = $snapshot['sale_price'] === null
            ? null
            : $this->withTax($product, $snapshot['sale_price']);
        $resolved = $this->withTax($product, $snapshot['resolved_price']);
        $compareAt = $resolved < $regular ? $regular : null;

        return [
            'regular_price' => $regular,
            'sale_price' => $sale,
            'resolved_price' => $resolved,
            'display_price' => $resolved,
            'compare_at_price' => $compareAt,
            'label' => match ($snapshot['source']) {
                'price_list' => 'Your Price',
                'branch_price' => 'Branch Price',
                'sale_price' => 'Sale',
                default => 'Regular Price',
            },
            'has_customer_price' => $snapshot['source'] === 'price_list',
            'has_sale_price' => $sale !== null,
            'price_source' => $snapshot['source'],
            'currency' => $snapshot['currency'],
            'price_list_id' => $snapshot['price_list_id'],
            'price_list_code' => $snapshot['price_list_code'],
            'branch_id' => $snapshot['branch_id'],
            'branch_code' => $snapshot['branch_code'],
            'branch_price_id' => $snapshot['branch_price_id'],
            'formatted_regular_price' => $this->format($regular),
            'formatted_sale_price' => $sale === null ? null : $this->format($sale),
            'formatted_display_price' => $this->format($resolved),
            'formatted_compare_at_price' => $compareAt === null ? null : $this->format($compareAt),
        ];
    }

    public function displayRange(Product $product, ?User $customer = null): array
    {
        $subjects = $product->variant_product
            ? $product->stocks
            : collect([$product]);
        if ($subjects->isEmpty()) {
            $subjects = collect([$product]);
        }

        $displays = $subjects->map(fn (Product|ProductStock $subject) => $this->display($subject, $customer));
        $minimum = $displays->min('display_price');
        $maximum = $displays->max('display_price');

        return [
            'minimum' => $minimum,
            'maximum' => $maximum,
            'raw' => $minimum === $maximum ? (string) $minimum : $minimum.' - '.$maximum,
            'formatted' => $minimum === $maximum
                ? $this->format($minimum)
                : $this->format($minimum).' - '.$this->format($maximum),
        ];
    }

    private function eligibleCustomer(?User $customer): ?User
    {
        $customer ??= auth()->user();

        return $customer?->user_type === 'customer' ? $customer : null;
    }

    private function withTax(Product $product, float $price): float
    {
        $tax = 0.0;
        foreach ($product->taxes as $productTax) {
            $tax += $productTax->tax_type === 'percent'
                ? $price * ((float) $productTax->tax / 100)
                : (float) $productTax->tax;
        }

        return $this->money->normalizeMoney($price + $tax);
    }

    private function format(float $price): string
    {
        return format_price(convert_price($price));
    }
}
