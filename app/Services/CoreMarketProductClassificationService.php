<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductFamily;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CoreMarketProductClassificationService
{
    public function families(bool $activeOnly = true): Collection
    {
        return ProductFamily::query()
            ->families()
            ->when($activeOnly, fn (Builder $query) => $query->active())
            ->with(['children' => fn ($query) => $query->when($activeOnly, fn ($children) => $children->active())])
            ->orderBy('name')
            ->get();
    }

    public function subFamiliesForFamily(int $familyId, bool $activeOnly = true): Collection
    {
        return ProductFamily::query()
            ->subFamilies()
            ->where('parent_id', $familyId)
            ->when($activeOnly, fn (Builder $query) => $query->active())
            ->orderBy('name')
            ->get();
    }

    public function assignFamily(Product $product, ?ProductFamily $family, ?ProductFamily $subFamily): Product
    {
        [$family, $subFamily] = $this->validateFamilyHierarchy($family?->id, $subFamily?->id);
        $product->forceFill([
            'product_family_id' => $family?->id,
            'product_sub_family_id' => $subFamily?->id,
        ])->save();

        return $product->refresh();
    }

    public function validateFamilyHierarchy(mixed $familyId, mixed $subFamilyId): array
    {
        if (blank($familyId) && blank($subFamilyId)) {
            return [null, null];
        }
        if (blank($familyId) && ! blank($subFamilyId)) {
            throw new DomainException('A sub family requires a product family.');
        }

        $family = ProductFamily::query()->find($familyId);
        if (! $family || $family->level !== 'family' || $family->parent_id !== null || ! $family->is_active) {
            throw new DomainException('Selected product family is unavailable.');
        }

        if (blank($subFamilyId)) {
            return [$family, null];
        }

        $subFamily = ProductFamily::query()->find($subFamilyId);
        if (
            ! $subFamily
            || $subFamily->level !== 'sub_family'
            || (int) $subFamily->parent_id !== (int) $family->id
            || ! $subFamily->is_active
        ) {
            throw new DomainException('Selected sub family does not belong to the product family.');
        }

        return [$family, $subFamily];
    }

    public function classificationSnapshot(Product $product): array
    {
        $product->loadMissing(['productFamily', 'productSubFamily']);

        return [
            'family_id' => $product->productFamily?->id,
            'family_code' => $product->productFamily?->code,
            'family_name' => $product->productFamily?->name,
            'sub_family_id' => $product->productSubFamily?->id,
            'sub_family_code' => $product->productSubFamily?->code,
            'sub_family_name' => $product->productSubFamily?->name,
        ];
    }

    public function productsByFamily(int $familyId): Builder
    {
        return Product::query()->where('product_family_id', $familyId);
    }

    public function productsBySubFamily(int $subFamilyId): Builder
    {
        return Product::query()->where('product_sub_family_id', $subFamilyId);
    }
}
