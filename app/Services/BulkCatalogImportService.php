<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Category;
use App\Models\CategoryTranslation;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Upload;
use App\Services\ProductInformationSectionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use ZipArchive;

class BulkCatalogImportService
{
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public function preview(string $type, UploadedFile $spreadsheet, ?UploadedFile $images, int $userId): array
    {
        $token = (string) Str::uuid();
        $directory = storage_path("app/bulk-catalog/{$token}");
        File::ensureDirectoryExists($directory);
        $spreadsheet->move($directory, 'catalog.xlsx');
        if ($images) $images->move($directory, 'images.zip');

        $rows = $this->readRows("{$directory}/catalog.xlsx");
        $files = $this->validateZip("{$directory}/images.zip", $images !== null);
        $report = $this->analyse($type, $rows, $files);
        $report['token'] = $token;
        $report['type'] = $type;
        $report['user_id'] = $userId;
        file_put_contents("{$directory}/preview.json", json_encode($report));

        return $report;
    }

    public function confirm(string $token, int $userId): array
    {
        $directory = storage_path("app/bulk-catalog/{$token}");
        $preview = json_decode((string) @file_get_contents("{$directory}/preview.json"), true);
        abort_unless(is_array($preview) && (int) $preview['user_id'] === $userId, 404);
        if (! empty($preview['errors'])) return $preview;

        $rows = $this->readRows("{$directory}/catalog.xlsx");
        $uploads = [];
        DB::transaction(function () use ($preview, $rows, $directory, $userId, &$uploads) {
            $uploads = $this->persist($preview['type'], $rows, $directory, $userId);
        });
        File::deleteDirectory($directory);
        return ['created' => $uploads['created'], 'updated' => $uploads['updated'], 'skipped' => 0, 'errors' => []];
    }

    private function readRows(string $path): array
    {
        $sheets = Excel::toArray([], $path);
        $sheet = $sheets[0] ?? [];
        if (! $sheet) return [];
        $headers = array_map(fn ($value) => Str::snake(trim((string) $value)), array_shift($sheet));
        return collect($sheet)->map(function ($row, $index) use ($headers) {
            $row = array_pad($row, count($headers), null);
            $data = array_combine($headers, array_slice($row, 0, count($headers)));
            return array_map(fn ($value) => is_string($value) ? trim($value) : $value, $data) + ['_row' => $index + 2];
        })->filter(fn ($row) => collect($row)->except('_row')->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty())->values()->all();
    }

    private function validateZip(string $path, bool $provided): array
    {
        if (! $provided) return [];
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) return ['__error__' => 'Unable to read images ZIP.'];
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i); $name = str_replace('\\', '/', $stat['name']);
            if (str_contains($name, '../') || str_starts_with($name, '/') || str_ends_with($name, '/')) { $files['__error__'] = 'Unsafe ZIP path detected.'; break; }
            $base = basename($name); $extension = strtolower(pathinfo($base, PATHINFO_EXTENSION));
            if (! in_array($extension, self::IMAGE_EXTENSIONS, true)) { $files['__error__'] = "Unsupported image: {$base}"; break; }
            if (isset($files[$base])) { $files['__error__'] = "Duplicate image filename: {$base}"; break; }
            if (($stat['size'] ?? 0) > 10 * 1024 * 1024) { $files['__error__'] = "Image exceeds 10 MB: {$base}"; break; }
            $files[$base] = $name;
        }
        $zip->close(); return $files;
    }

    private function analyse(string $type, array $rows, array $files): array
    {
        $errors = isset($files['__error__']) ? [$files['__error__']] : [];
        unset($files['__error__']); $created = 0; $updated = 0; $seen = [];
        if (! $rows) $errors[] = 'The spreadsheet has no data rows.';
        foreach ($rows as $row) {
            $error = $this->rowError($type, $row, $files, $seen);
            if ($error) { $errors[] = "Row {$row['_row']}: {$error}"; continue; }
            $this->matchesExisting($type, $row) ? $updated++ : $created++;
        }
        if ($type === 'categories') $errors = array_merge($errors, $this->categoryTreeErrors($rows));
        return compact('created', 'updated', 'errors') + ['rows' => $rows, 'image_files' => array_keys($files)];
    }

    private function rowError(string $type, array $row, array $files, array &$seen): ?string
    {
        if (blank($row['name'] ?? null)) return 'Name is required.';
        if ($type === 'categories' && blank($row['row_key'] ?? null)) return 'row_key is required for categories.';
        if ($type === 'products' && blank($row['unit_price'] ?? null)) return 'unit_price is required for products.';
        if ($type === 'products' && blank($row['category_slug'] ?? null) && blank($row['category_id'] ?? null)) return 'category_slug or category_id is required for products.';
        if ($type === 'products' && ! is_numeric($row['unit_price'])) return 'unit_price must be numeric.';
        $identity = match ($type) {
            'categories' => strtolower((string) $row['row_key']),
            'products' => $this->normalise((string) (($row['sku'] ?? null) ?: ($row['barcode'] ?? null) ?: $row['name'])),
            default => $this->normalise((string) $row['name']),
        };
        if (isset($seen[$identity])) return 'Duplicate identity inside the uploaded file.';
        $seen[$identity] = true;
        foreach (['cover_image_file', 'banner_image_file', 'icon_file', 'logo_file', 'thumbnail_file'] as $column) {
            if (filled($row[$column] ?? null) && ! isset($files[$row[$column]])) return "Image file {$row[$column]} is missing from ZIP.";
        }
        foreach (array_filter(explode(';', (string) ($row['gallery_files'] ?? ''))) as $file) if (! isset($files[trim($file)])) return "Gallery image {$file} is missing from ZIP.";
        if ($type === 'products' && array_key_exists('information_sections', $row)) {
            try {
                $this->parseInformationSections($row);
            } catch (\InvalidArgumentException $exception) {
                return $exception->getMessage();
            }
        }
        return null;
    }

    private function categoryTreeErrors(array $rows): array
    {
        $keys = []; foreach ($rows as $row) { $key = strtolower((string) ($row['row_key'] ?? '')); if (isset($keys[$key])) return ["Duplicate category row_key: {$key}"]; $keys[$key] = $row; }
        foreach ($keys as $key => $row) {
            $parent = strtolower((string) ($row['parent_row_key'] ?? ''));
            if ($parent && ! isset($keys[$parent]) && ! Category::query()->where('slug', $row['parent_slug'] ?? '')->exists()) return ["Row {$row['_row']}: parent_row_key {$parent} is missing."];
            $visited = []; $current = $key;
            while (($parent = strtolower((string) ($keys[$current]['parent_row_key'] ?? ''))) !== '') { if (isset($visited[$parent])) return ["Category cycle detected at {$key}."]; $visited[$parent] = true; $current = $parent; if (! isset($keys[$current])) break; }
        }
        return [];
    }

    private function matchesExisting(string $type, array $row): bool
    {
        return match ($type) {
            'brands' => Brand::query()->get()->contains(fn ($brand) => $this->normalise($brand->name) === $this->normalise($row['name'])),
            'products' => $this->productMatch($row) !== null,
            default => false,
        };
    }

    private function persist(string $type, array $rows, string $directory, int $userId): array
    {
        return match ($type) {
            'categories' => $this->persistCategories($rows, $directory, $userId),
            'brands' => $this->persistBrands($rows, $directory, $userId),
            'products' => $this->persistProducts($rows, $directory, $userId),
        };
    }

    private function persistCategories(array $rows, string $directory, int $userId): array
    {
        $map = []; $result = ['created'=>0,'updated'=>0]; $remaining = $rows;
        while ($remaining) {
            $progress = false;
            foreach ($remaining as $index => $row) {
                $parentKey = strtolower((string) ($row['parent_row_key'] ?? ''));
                if ($parentKey && ! isset($map[$parentKey])) continue;
                $parentId = $parentKey ? $map[$parentKey]->id : 0;
                $category = Category::query()->where('parent_id', $parentId)->get()->first(fn ($item) => $this->normalise($item->name) === $this->normalise($row['name']));
                $new = ! $category; $category ??= new Category();
                $category->name = $row['name']; $category->parent_id = $parentId; $category->level = $parentKey ? $map[$parentKey]->level + 1 : 0;
                $category->slug = filled($row['slug'] ?? null) ? Str::slug($row['slug']) : ($category->slug ?: Str::slug($row['name']).'-'.Str::random(5));
                foreach (['meta_title','meta_description','order_level','digital'] as $field) if (filled($row[$field] ?? null)) $category->{$field} = $row[$field];
                foreach (['cover_image_file'=>'cover_image','banner_image_file'=>'banner','icon_file'=>'icon'] as $column=>$field) if (filled($row[$column] ?? null)) $category->{$field} = $this->storeImage($directory, $row[$column], $userId);
                $category->save(); CategoryTranslation::updateOrCreate(['category_id'=>$category->id,'lang'=>env('DEFAULT_LANGUAGE','en')], ['name'=>$category->name]);
                $map[strtolower($row['row_key'])] = $category; unset($remaining[$index]); $new ? $result['created']++ : $result['updated']++; $progress = true;
            }
            if (! $progress) throw new \RuntimeException('Category parents could not be resolved.');
        }
        return $result;
    }

    private function persistBrands(array $rows, string $directory, int $userId): array
    {
        $result=['created'=>0,'updated'=>0]; foreach ($rows as $row) { $brand=Brand::query()->get()->first(fn($item)=>$this->normalise($item->name)===$this->normalise($row['name'])); $new=!$brand; $brand??=new Brand(); $brand->name=$row['name']; $brand->slug=filled($row['slug']??null)?Str::slug($row['slug']):($brand->slug?:Str::slug($row['name']).'-'.Str::random(5)); foreach(['meta_title','meta_description'] as $f)if(filled($row[$f]??null))$brand->{$f}=$row[$f]; if(filled($row['logo_file']??null))$brand->logo=$this->storeImage($directory,$row['logo_file'],$userId); $brand->save(); \App\Models\BrandTranslation::updateOrCreate(['brand_id'=>$brand->id,'lang'=>env('DEFAULT_LANGUAGE','en')],['name'=>$brand->name]); $new?$result['created']++:$result['updated']++; } return $result;
    }

    private function persistProducts(array $rows, string $directory, int $userId): array
    {
        $result = ['created' => 0, 'updated' => 0];

        foreach ($rows as $row) {
            $product = $this->productMatch($row);
            $new = ! $product;
            $product ??= new Product();
            $category = $this->categoryFromRow($row);
            $brand = $this->brandFromRow($row);

            foreach (['name', 'description', 'unit', 'unit_price', 'tags', 'meta_title', 'meta_description', 'est_shipping_days', 'video_provider', 'video_link'] as $field) {
                if (filled($row[$field] ?? null)) $product->{$field} = $row[$field];
            }

            $product->category_id = $category?->id ?? $product->category_id;
            $product->brand_id = $brand?->id ?? $product->brand_id;
            $product->added_by = 'admin';
            $product->user_id = $product->user_id ?: $userId;
            $product->approved = 1;
            $product->slug = filled($row['slug'] ?? null) ? Str::slug($row['slug']) : ($product->slug ?: Str::slug($row['name']) . '-' . Str::random(5));
            if (filled($row['barcode'] ?? null)) $product->barcode = $row['barcode'];
            if (filled($row['thumbnail_file'] ?? null)) $product->thumbnail_img = $this->storeImage($directory, $row['thumbnail_file'], $userId);
            if (filled($row['gallery_files'] ?? null)) $product->photos = collect(explode(';', $row['gallery_files']))->map(fn ($file) => $this->storeImage($directory, trim($file), $userId))->implode(',');
            $product->save();

            $stock = $product->stocks()->where('variant', '')->first() ?? new ProductStock(['variant' => '']);
            $stock->product_id = $product->id;
            foreach (['sku', 'barcode'] as $field) if (filled($row[$field] ?? null)) $stock->{$field} = $row[$field];
            $stock->price = $product->unit_price;
            $stock->qty = $row['qty'] ?? $stock->qty ?? 0;
            $stock->save();

            DB::table('product_categories')->updateOrInsert(
                ['product_id' => $product->id, 'category_id' => $product->category_id]
            );
            \App\Models\ProductTranslation::updateOrCreate(
                ['product_id' => $product->id, 'lang' => env('DEFAULT_LANGUAGE', 'en')],
                ['name' => $product->name, 'unit' => $product->unit, 'description' => $product->description]
            );

            if (array_key_exists('information_sections', $row) && filled($row['information_sections'])) {
                app(ProductInformationSectionService::class)->replaceFromBulk($product, $this->parseInformationSections($row));
            }

            $new ? $result['created']++ : $result['updated']++;
        }

        return $result;
    }

    public function parseInformationSections(array $row): array
    {
        $raw = trim((string) ($row['information_sections'] ?? ''));
        if ($raw === '') return [];
        try {
            $sections = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('information_sections must be a valid JSON array.');
        }
        if (! is_array($sections) || ! array_is_list($sections)) throw new \InvalidArgumentException('information_sections must be a JSON array.');

        $defaultLanguage = env('DEFAULT_LANGUAGE', 'en');
        return collect($sections)->values()->map(function ($section, $index) use ($defaultLanguage) {
            if (! is_array($section)) throw new \InvalidArgumentException('Each information section must be an object.');
            $title = trim((string) ($section['title'] ?? ''));
            $content = (string) ($section['content'] ?? '');
            if ($title === '' || trim(strip_tags($content)) === '') throw new \InvalidArgumentException('Each information section requires title and content.');
            if (mb_strlen($title) > 255) throw new \InvalidArgumentException('An information section title may not exceed 255 characters.');
            $translations = [$defaultLanguage => ['title' => $title, 'content' => $content]];
            if (isset($section['translations']) && ! is_array($section['translations'])) throw new \InvalidArgumentException('Information section translations must be an object.');
            foreach (($section['translations'] ?? []) as $lang => $translation) {
                if (! is_array($translation)) throw new \InvalidArgumentException('Each information section translation must be an object.');
                $translatedTitle = trim((string) ($translation['title'] ?? ''));
                $translatedContent = (string) ($translation['content'] ?? '');
                if (! is_string($lang) || trim($lang) === '' || $translatedTitle === '' || trim(strip_tags($translatedContent)) === '') throw new \InvalidArgumentException('Each information section translation requires language, title and content.');
                if (mb_strlen($translatedTitle) > 255) throw new \InvalidArgumentException('An information section translation title may not exceed 255 characters.');
                $translations[trim($lang)] = ['title' => $translatedTitle, 'content' => $translatedContent];
            }
            $isActive = filter_var($section['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isActive === null) throw new \InvalidArgumentException('Information section is_active must be true or false.');
            return ['sort_order' => is_numeric($section['sort_order'] ?? null) ? (int) $section['sort_order'] : $index + 1, 'is_active' => $isActive, 'translations' => $translations];
        })->all();
    }

    private function productMatch(array $row): ?Product
    {
        if (filled($row['sku'] ?? null) && ($stock = ProductStock::query()->with('product')->where('sku', $row['sku'])->first())) return $stock->product;
        $identity = app(ProductIdentityLookupService::class);
        if (filled($row['barcode'] ?? null) && ($found = $identity->find((string) $row['barcode']))) return $found['product'];
        if (blank($row['sku']??null) && blank($row['barcode']??null) && ($category=$this->categoryFromRow($row))) return Product::query()->where('category_id',$category->id)->get()->first(fn($item)=>$this->normalise($item->name)===$this->normalise($row['name']));
        return null;
    }

    private function categoryFromRow(array $row): ?Category { if (filled($row['category_slug']??null)) return Category::query()->where('slug',$row['category_slug'])->first(); return filled($row['category_id']??null)?Category::find($row['category_id']):null; }
    private function brandFromRow(array $row): ?Brand { if (filled($row['brand_slug']??null)) return Brand::query()->where('slug',$row['brand_slug'])->first(); return filled($row['brand_id']??null)?Brand::find($row['brand_id']):null; }

    private function storeImage(string $directory, string $name, int $userId): int
    {
        $zip=new ZipArchive(); $zip->open("{$directory}/images.zip"); $content=$zip->getFromName($name); $zip->close(); if($content===false) throw new \RuntimeException("Image {$name} was not found."); $extension=strtolower(pathinfo($name,PATHINFO_EXTENSION)); $path='uploads/all/'.Str::random(40).'.'.$extension; File::ensureDirectoryExists(public_path('uploads/all')); file_put_contents(public_path($path),$content); return Upload::create(['file_original_name'=>pathinfo($name,PATHINFO_FILENAME),'file_name'=>$path,'user_id'=>$userId,'extension'=>$extension,'type'=>'image','file_size'=>strlen($content)])->id;
    }
    private function normalise(string $value): string { return preg_replace('/\s+/u',' ',mb_strtolower(trim($value))); }
}
