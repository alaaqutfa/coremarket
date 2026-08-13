<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductInformationSection;
use Illuminate\Support\Arr;

class ProductInformationSectionService
{
    public function replaceFromBulk(Product $product, array $sections): void
    {
        $product->informationSections()->delete();

        foreach ($sections as $position => $payload) {
            $section = $product->informationSections()->create([
                'sort_order' => (int) $payload['sort_order'],
                'is_active' => (bool) $payload['is_active'],
            ]);
            foreach ($payload['translations'] as $lang => $translation) {
                $section->translations()->create([
                    'lang' => $lang,
                    'title' => $translation['title'],
                    'content' => $translation['content'],
                ]);
            }
        }
    }

    public function sync(Product $product, array $sections, ?string $lang = null): void
    {
        $lang ??= env('DEFAULT_LANGUAGE', 'en');
        $existing = $product->informationSections()->get()->keyBy('id');
        $kept = [];

        foreach (array_values($sections) as $position => $payload) {
            $title = trim((string) Arr::get($payload, 'title'));
            $content = (string) Arr::get($payload, 'content');

            if ($title === '' || trim(strip_tags($content)) === '') {
                continue;
            }

            $sectionId = Arr::get($payload, 'id');
            $section = $sectionId && $existing->has($sectionId)
                ? $existing->get($sectionId)
                : new ProductInformationSection(['product_id' => $product->id]);

            $section->sort_order = is_numeric(Arr::get($payload, 'sort_order'))
                ? (int) Arr::get($payload, 'sort_order')
                : $position + 1;
            $section->is_active = Arr::get($payload, 'is_active', false) ? true : false;
            $section->save();
            $section->translations()->updateOrCreate(
                ['lang' => $lang],
                ['title' => $title, 'content' => $content]
            );
            $kept[] = $section->id;
        }

        if ($kept === []) {
            $product->informationSections()->delete();
            return;
        }

        $product->informationSections()->whereNotIn('id', $kept)->delete();
    }

    public function duplicate(Product $source, Product $target): void
    {
        $source->loadMissing('informationSections.translations');

        foreach ($source->informationSections as $sourceSection) {
            $section = $target->informationSections()->create([
                'sort_order' => $sourceSection->sort_order,
                'is_active' => $sourceSection->is_active,
            ]);

            foreach ($sourceSection->translations as $translation) {
                $section->translations()->create([
                    'lang' => $translation->lang,
                    'title' => $translation->title,
                    'content' => $translation->content,
                ]);
            }
        }
    }
}
