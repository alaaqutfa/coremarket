<?php

namespace App\Services;

use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use DomainException;

class CoreMarketPriceListService
{
    public function __construct(private CoreMarketMoneyService $money)
    {
    }

    public function resolvePrice(Product|ProductStock $subject, ?User $customer = null, array $context = []): float
    {
        return $this->pricingSnapshot($subject, $customer, $context)['resolved_price'];
    }

    public function getCustomerPriceList(?User $customer): ?PriceList
    {
        if (! $customer || ! $customer->price_list_id) {
            return null;
        }

        return PriceList::query()->whereKey($customer->price_list_id)->where('is_active', true)->first();
    }

    public function activePriceListItem(PriceList $priceList, Product $product, ?ProductStock $stock = null): ?PriceListItem
    {
        return PriceListItem::query()
            ->where('price_list_id', $priceList->id)
            ->where('is_active', true)
            ->where(fn ($query) => $query
                ->when($stock, fn ($items) => $items->orWhere('product_stock_id', $stock->id))
                ->orWhere(fn ($items) => $items->where('product_id', $product->id)->whereNull('product_stock_id')))
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->orderByRaw('product_stock_id IS NULL')
            ->orderByDesc('id')
            ->first();
    }

    public function calculatePriceFromMethod(
        string $method,
        float $regularPrice,
        float $costPrice,
        mixed $fixedPrice,
        mixed $marginPercent,
        mixed $discountPercent
    ): float {
        $price = match ($method) {
            'fixed_price' => is_numeric($fixedPrice)
                ? (float) $fixedPrice
                : throw new DomainException('Fixed price list item requires a fixed price.'),
            'margin_over_cost' => $costPrice + ($costPrice * ((float) $marginPercent / 100)),
            'discount_from_regular' => $regularPrice - ($regularPrice * ((float) $discountPercent / 100)),
            default => throw new DomainException('Unknown price list pricing method.'),
        };

        if ($price < 0) {
            throw new DomainException('Resolved price list price cannot be negative.');
        }

        return $this->money->normalizeMoney($price);
    }

    public function pricingSnapshot(Product|ProductStock $subject, ?User $customer = null, array $context = []): array
    {
        [$product, $stock] = $subject instanceof ProductStock
            ? [$subject->relationLoaded('product') ? $subject->product : $subject->product()->firstOrFail(), $subject]
            : [$subject, $context['product_stock'] ?? null];
        $regularValue = $context['regular_price'] ?? (is_numeric($stock?->price) ? $stock->price : $product->unit_price);
        if (! is_numeric($regularValue) || (float) $regularValue < 0) {
            throw new DomainException('Product regular price is invalid for sale.');
        }
        $regularPrice = $this->money->normalizeMoney($regularValue);
        $salePrice = array_key_exists('sale_price', $context)
            ? $this->nullableMoney($context['sale_price'])
            : $this->activeSalePrice($product, $regularPrice);
        $priceList = $this->getCustomerPriceList($customer);
        $item = $priceList ? $this->activePriceListItem($priceList, $product, $stock) : null;
        $listPrice = null;
        $currency = strtoupper((string) ($context['currency'] ?? $this->money->baseCurrency()));

        if ($priceList && $item) {
            if (strtoupper((string) $priceList->currency) !== $currency) {
                throw new DomainException('Customer price list currency does not match the sale currency.');
            }
            if ($priceList->pricing_method === 'margin_over_cost' && ! is_numeric($product->purchase_price)) {
                throw new DomainException('Margin price list requires a valid product cost.');
            }
            $listPrice = $this->calculatePriceFromMethod(
                $priceList->pricing_method,
                $regularPrice,
                is_numeric($product->purchase_price) ? (float) $product->purchase_price : 0.0,
                $item->fixed_price,
                $item->margin_percent ?? $priceList->margin_percent,
                $item->discount_percent ?? $priceList->discount_percent
            );
        }

        $priority = $context['priority'] ?? config('coremarket.pricing.priority', 'customer_price_first');
        [$source, $resolved] = $this->choosePrice($priority, $regularPrice, $salePrice, $listPrice);

        return [
            'source' => $source,
            'price_list_id' => $source === 'price_list' ? $priceList?->id : null,
            'price_list_code' => $source === 'price_list' ? $priceList?->code : null,
            'price_list_item_id' => $source === 'price_list' ? $item?->id : null,
            'base_regular_price' => $regularPrice,
            'sale_price' => $salePrice,
            'resolved_price' => $this->money->normalizeMoney($resolved),
            'currency' => $currency,
            'priority' => $priority,
        ];
    }

    private function activeSalePrice(Product $product, float $regularPrice): ?float
    {
        if (! is_numeric($product->discount) || (float) $product->discount <= 0) {
            return null;
        }
        $now = now()->timestamp;
        if ($product->discount_start_date && $now < (int) $product->discount_start_date) return null;
        if ($product->discount_end_date && $now > (int) $product->discount_end_date) return null;

        $price = $product->discount_type === 'percent'
            ? $regularPrice * (1 - ((float) $product->discount / 100))
            : $regularPrice - (float) $product->discount;

        return $this->money->normalizeMoney(max(0, $price));
    }

    private function choosePrice(string $priority, float $regular, ?float $sale, ?float $list): array
    {
        if ($priority === 'lowest_price') {
            $candidates = ['regular_price' => $regular];
            if ($sale !== null) $candidates['sale_price'] = $sale;
            if ($list !== null) $candidates['price_list'] = $list;
            $lowest = min($candidates);
            return [array_search($lowest, $candidates, true), $lowest];
        }
        if ($priority === 'sale_price_first' && $sale !== null) return ['sale_price', $sale];
        if ($list !== null) return ['price_list', $list];
        if ($sale !== null) return ['sale_price', $sale];
        return ['regular_price', $regular];
    }

    private function nullableMoney(mixed $value): ?float
    {
        return is_numeric($value) ? $this->money->normalizeMoney($value) : null;
    }
}
