<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductTranslation;
use App\Models\Category;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CoreMarketProductQuickCreateService
{
    public function __construct(
        private ProductIdentityLookupService $identityLookup,
        private CoreMarketProductClassificationService $classification,
        private CoreMarketProductPricingService $pricing,
        private CoreMarketInventoryPolicyService $inventoryPolicy,
        private CoreMarketPricingFeatureService $pricingFeatures,
        private CoreMarketBranchService $branches,
        private CoreMarketLicenseService $license
    ) {
    }

    public function create(array $payload, User $actor): array
    {
        if (! $this->license->canCreateProducts()) {
            throw new DomainException($this->license->productLimitMessage());
        }

        $identityErrors = $this->identityLookup->validationErrors(null, [[
            'sku' => $payload['sku'] ?? null,
            'sku_key' => 'sku',
            'barcode' => $payload['barcode'] ?? null,
            'barcode_key' => 'barcode',
        ]]);
        if ($identityErrors !== []) {
            throw new QuickProductValidationException($identityErrors);
        }

        [$family, $subFamily] = $this->classification->validateFamilyHierarchy(
            $payload['product_family_id'] ?? null,
            $payload['product_sub_family_id'] ?? null
        );
        $pricing = $this->pricing->normalize($payload);
        $categoryId = $payload['category_id']
            ?? Category::query()->where('digital', 0)->where('active', 1)->value('id')
            ?? Category::query()->where('digital', 0)->value('id');
        if (! $categoryId) {
            throw new QuickProductValidationException([
                'category_id' => ['Create a storefront category before adding products.'],
            ]);
        }
        $openingStock = max(0, (float) ($payload['opening_stock'] ?? 0));
        if (! $this->inventoryPolicy->canCreateOpeningStock() && $openingStock > 0) {
            throw new QuickProductValidationException([
                'opening_stock' => ['Opening stock is disabled while strict inventory mode is enabled.'],
            ]);
        }

        return DB::transaction(function () use ($payload, $actor, $family, $subFamily, $pricing, $openingStock, $categoryId) {
            $ownerId = $actor->user_type === 'admin'
                ? $actor->id
                : User::query()->where('user_type', 'admin')->value('id');
            if (! $ownerId) {
                throw new DomainException('A store owner account is required to create products.');
            }

            $slugBase = Str::slug($payload['name']) ?: 'product';
            $slug = $slugBase;
            for ($suffix = 2; Product::query()->where('slug', $slug)->exists(); $suffix++) {
                $slug = "{$slugBase}-{$suffix}";
            }

            $product = Product::query()->create(array_merge([
                'name' => trim($payload['name']),
                'user_id' => $ownerId,
                'added_by' => 'admin',
                'slug' => $slug,
                'unit' => trim((string) ($payload['unit'] ?? 'pc')) ?: 'pc',
                'min_qty' => 1,
                'current_stock' => $openingStock,
                'barcode' => null,
                'brand_id' => $payload['brand_id'] ?? null,
                'category_id' => $categoryId,
                'product_family_id' => $family?->id,
                'product_sub_family_id' => $subFamily?->id,
                'description' => $payload['description'] ?? null,
                'published' => 1,
                'approved' => 1,
                'digital' => 0,
                'auction_product' => 0,
                'wholesale_product' => 0,
                'variant_product' => 0,
                'attributes' => '[]',
                'choice_options' => '[]',
                'colors' => '[]',
            ], $this->pricing->productFields($pricing)));

            $product->categories()->syncWithoutDetaching([(int) $categoryId]);

            $stock = ProductStock::query()->create([
                'product_id' => $product->id,
                'variant' => '',
                'sku' => $this->nullableString($payload['sku'] ?? null),
                'barcode' => $this->nullableString($payload['barcode'] ?? null),
                'price' => $pricing['regular_price'],
                'qty' => $openingStock,
            ]);

            ProductTranslation::query()->create([
                'product_id' => $product->id,
                'lang' => env('DEFAULT_LANGUAGE', 'en'),
                'name' => $product->name,
                'unit' => $product->unit,
                'description' => $product->description,
            ]);

            $branch = $this->branches->branchesEnabled() ? $this->branches->defaultBranch() : null;

            return [
                'product_id' => $product->id,
                'product_stock_id' => $stock->id,
                'name' => $product->name,
                'variant' => '',
                'sku' => $stock->sku,
                'barcode' => $stock->barcode,
                'cost_price' => $pricing['cost_price'],
                'regular_price' => $pricing['regular_price'],
                'sale_price' => $pricing['sale_price'],
                'margin_percent' => $pricing['margin_percent'],
                'tax_enabled' => filter_var($payload['tax_enabled'] ?? false, FILTER_VALIDATE_BOOL),
                'tax_rate' => is_numeric($payload['tax_rate'] ?? null) ? (float) $payload['tax_rate'] : 0,
                'opening_stock' => $openingStock,
                'strict_inventory_mode' => $this->inventoryPolicy->strictInventoryMode(),
                'price_lists_enabled' => $this->pricingFeatures->priceListsEnabled(),
                'branch' => $branch ? $this->branches->branchSnapshot($branch) : null,
            ];
        });
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
