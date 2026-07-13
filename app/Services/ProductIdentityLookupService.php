<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductStock;

class ProductIdentityLookupService
{
    public function find(string $identity): ?array
    {
        $identity = trim($identity);

        if ($identity === '') {
            return null;
        }

        $stock = ProductStock::query()->with('product')->where('barcode', $identity)->first();
        if ($stock) {
            return $this->result($stock->product, $stock, 'variant_barcode');
        }

        $product = Product::query()->with('stocks')->where('barcode', $identity)->first();
        if ($product) {
            $stock = $product->stocks->firstWhere('variant', '') ?: $product->stocks->first();

            return $this->result($product, $stock, 'product_barcode');
        }

        $stock = ProductStock::query()->with('product')->where('sku', $identity)->first();

        return $stock ? $this->result($stock->product, $stock, 'sku') : null;
    }

    public function validationErrors(?string $productBarcode, array $stockIdentities, ?int $productId = null): array
    {
        $errors = [];
        $productBarcode = $this->normalize($productBarcode);

        if ($productBarcode && Product::query()->where('barcode', $productBarcode)->when($productId, fn ($query) => $query->where('id', '!=', $productId))->exists()) {
            $errors['barcode'][] = translate('This product barcode is already in use.');
        }

        if ($productBarcode && ProductStock::query()->where('barcode', $productBarcode)->exists()) {
            $errors['barcode'][] = translate('This barcode is already assigned to a product variant.');
        }

        $seenBarcodes = [];
        $seenSkus = [];

        foreach ($stockIdentities as $identity) {
            $barcode = $this->normalize($identity['barcode'] ?? null);
            $sku = $this->normalize($identity['sku'] ?? null);
            $barcodeKey = $identity['barcode_key'] ?? 'barcode';
            $skuKey = $identity['sku_key'] ?? 'sku';

            if ($barcode) {
                if (isset($seenBarcodes[$barcode])) {
                    $errors[$barcodeKey][] = translate('Variant barcodes must be unique.');
                }
                $seenBarcodes[$barcode] = true;

                if (ProductStock::query()->where('barcode', $barcode)->when($productId, fn ($query) => $query->where('product_id', '!=', $productId))->exists()) {
                    $errors[$barcodeKey][] = translate('This variant barcode is already in use.');
                }

                if (Product::query()->where('barcode', $barcode)->exists()) {
                    $errors[$barcodeKey][] = translate('This barcode is already assigned to a product.');
                }
            }

            if ($sku) {
                if (isset($seenSkus[$sku])) {
                    $errors[$skuKey][] = translate('Variant SKUs must be unique.');
                }
                $seenSkus[$sku] = true;

                if (ProductStock::query()->where('sku', $sku)->when($productId, fn ($query) => $query->where('product_id', '!=', $productId))->exists()) {
                    $errors[$skuKey][] = translate('This SKU is already in use.');
                }
            }
        }

        return $errors;
    }

    private function result(Product $product, ?ProductStock $stock, string $matchedBy): array
    {
        return [
            'product' => $product,
            'product_stock' => $stock,
            'variant' => $stock?->variant,
            'qty' => $stock ? (int) $stock->qty : (int) $product->current_stock,
            'matched_by' => $matchedBy,
        ];
    }

    private function normalize(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
