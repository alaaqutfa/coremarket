<?php

namespace App\Models;

use App\Models\Product;
use App\Traits\PreventDemoModeChanges;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ProductsExport implements FromCollection, WithMapping, WithHeadings
{
    use PreventDemoModeChanges;

    public function collection()
    {
        return Product::query()->with(['stocks', 'informationSections.translations'])->get();
    }

    public function headings(): array
    {
        return [
            'name',
            'sku',
            'barcode',
            'category_id',
            'brand_id',
            'unit_price',
            'unit',
            'qty',
            'slug',
            'description',
            'tags',
            'meta_title',
            'meta_description',
            'information_sections',
        ];
    }

    /**
    * @var Product $product
    */
    public function map($product): array
    {
        $qty = 0;
        foreach ($product->stocks as $key => $stock) {
            $qty += $stock->qty;
        }
        return [
            $product->name,
            $product->stocks->firstWhere('variant', '')?->sku,
            $product->barcode ?: $product->stocks->firstWhere('variant', '')?->barcode,
            $product->category_id,
            $product->brand_id,
            $product->unit_price,
            $product->unit,
            $qty,
            $product->slug,
            $product->description,
            $product->tags,
            $product->meta_title,
            $product->meta_description,
            $this->informationSections($product),
        ];
    }

    private function informationSections(Product $product): string
    {
        $defaultLanguage = env('DEFAULT_LANGUAGE', 'en');

        return $product->informationSections->map(function ($section) use ($defaultLanguage) {
            $default = $section->translations->firstWhere('lang', $defaultLanguage)
                ?: $section->translations->first();

            return [
                'title' => $default?->title,
                'content' => $default?->content,
                'sort_order' => $section->sort_order,
                'is_active' => $section->is_active,
                'translations' => $section->translations
                    ->where('lang', '!=', $defaultLanguage)
                    ->mapWithKeys(fn ($translation) => [$translation->lang => [
                        'title' => $translation->title,
                        'content' => $translation->content,
                    ]])
                    ->all(),
            ];
        })->values()->toJson(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
