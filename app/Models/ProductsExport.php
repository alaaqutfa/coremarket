<?php

namespace App\Models;

use App\Traits\PreventDemoModeChanges;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ProductsExport implements FromCollection, WithHeadings
{
    use PreventDemoModeChanges;

    public function collection(): Collection
    {
        return Product::query()->with(['stocks', 'informationSections.translations', 'main_category', 'brand'])->get()
            ->flatMap(function (Product $product) {
                $stocks = $product->stocks->isNotEmpty() ? $product->stocks : collect([new ProductStock(['variant' => ''])]);
                $isVariant = (bool) $product->variant_product && $product->choiceOptionsArray() !== [];
                $default = $stocks->sortBy('id')->first();

                return $stocks->map(function (ProductStock $stock) use ($product, $isVariant, $default) {
                    $isDefault = $default?->id === $stock->id;
                    return [
                        'product_group_key' => $product->slug,
                        'name' => $product->name,
                        'sku' => $stock->sku,
                        'barcode' => $stock->barcode ?: $product->barcode,
                        'category_slug' => $product->main_category?->slug,
                        'category_id' => $product->category_id,
                        'category_path' => '',
                        'brand_slug' => $product->brand?->slug,
                        'brand_id' => $product->brand_id,
                        'variant_options' => $isVariant ? json_encode($this->variantOptions($product, $stock), JSON_UNESCAPED_UNICODE) : '',
                        'is_default_variant' => $isVariant && $isDefault ? 'true' : '',
                        'unit_price' => $stock->price ?? $product->unit_price,
                        'unit' => $product->unit,
                        'qty' => $stock->qty,
                        'slug' => $product->slug,
                        'description' => $product->description,
                        'tags' => $product->tags,
                        'meta_title' => $product->meta_title,
                        'meta_description' => $product->meta_description,
                        'thumbnail_file' => '',
                        'meta_img_file' => '',
                        'gallery_files' => '',
                        'variant_image_file' => '',
                        'information_sections' => $this->informationSections($product),
                    ];
                });
            })->values();
    }

    public function headings(): array
    {
        return ['product_group_key','name','sku','barcode','category_slug','category_id','category_path','brand_slug','brand_id','variant_options','is_default_variant','unit_price','unit','qty','slug','description','tags','meta_title','meta_description','thumbnail_file','meta_img_file','gallery_files','variant_image_file','information_sections'];
    }

    private function variantOptions(Product $product, ProductStock $stock): array
    {
        $values = explode('-', (string) $stock->variant);
        $options = [];
        foreach ($product->choiceOptionsArray() as $index => $choice) {
            $attribute = Attribute::find($choice->attribute_id);
            $options[$attribute?->name ?? 'Option '.($index + 1)] = $values[$index] ?? $stock->variant;
        }
        return $options;
    }

    private function informationSections(Product $product): string
    {
        $defaultLanguage = env('DEFAULT_LANGUAGE', 'en');
        return $product->informationSections->map(function ($section) use ($defaultLanguage) {
            $default = $section->translations->firstWhere('lang', $defaultLanguage) ?: $section->translations->first();
            return ['title'=>$default?->title,'content'=>$default?->content,'sort_order'=>$section->sort_order,'is_active'=>$section->is_active,'translations'=>$section->translations->where('lang','!=',$defaultLanguage)->mapWithKeys(fn($translation)=>[$translation->lang=>['title'=>$translation->title,'content'=>$translation->content]])->all()];
        })->values()->toJson(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
