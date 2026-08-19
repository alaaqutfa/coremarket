<?php

namespace App\Services;

use App\Models\Attribute;
use App\Models\AttributeTranslation;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CategoryTranslation;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Upload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use ZipArchive;

class BulkCatalogImportService
{
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];
    private const PARENT_FIELDS = ['name','category_slug','category_id','category_path','brand_slug','brand_id','slug','description','unit','tags','meta_title','meta_description','est_shipping_days','video_provider','video_link','thumbnail_file','meta_img_file','gallery_files','information_sections'];
    private array $storedImages = [];

    public function preview(string $type, UploadedFile $spreadsheet, ?UploadedFile $images, int $userId, bool $replaceProductImages = false): array
    {
        $token = (string) Str::uuid();
        $directory = storage_path("app/bulk-catalog/{$token}");
        File::ensureDirectoryExists($directory);
        $spreadsheet->move($directory, 'catalog.xlsx');
        if ($images) $images->move($directory, 'images.zip');
        $rows = $this->readRows("{$directory}/catalog.xlsx");
        $files = $this->validateZip("{$directory}/images.zip", $images !== null);
        $report = $this->analyse($type, $rows, $files);
        if ($type === 'products' && $replaceProductImages) {
            $report['errors'] = array_merge($report['errors'], $this->replacementImageErrors($rows));
        }
        $report += ['token'=>$token, 'type'=>$type, 'user_id'=>$userId, 'replace_product_images'=>$type === 'products' && $replaceProductImages];
        file_put_contents("{$directory}/preview.json", json_encode($report));
        return $report;
    }

    public function confirm(string $token, int $userId): array
    {
        $directory = storage_path("app/bulk-catalog/{$token}");
        $preview = json_decode((string) @file_get_contents("{$directory}/preview.json"), true);
        abort_unless(is_array($preview) && (int) $preview['user_id'] === $userId, 404);
        if (! empty($preview['errors'])) return $preview;
        $result = DB::transaction(fn () => $this->persist($preview['type'], $this->readRows("{$directory}/catalog.xlsx"), $directory, $userId, (bool) ($preview['replace_product_images'] ?? false)));
        if (! empty($result['media_cleanup'])) $result['media'] = app(ProductMediaReplacementService::class)->deleteUnreferenced($result['media_cleanup']);
        File::deleteDirectory($directory);
        return ['created'=>$result['created'], 'updated'=>$result['updated'], 'skipped'=>0, 'errors'=>[], 'media'=>$result['media'] ?? []];
    }

    public function previewFor(string $token, int $userId): array
    {
        $preview = json_decode((string) @file_get_contents(storage_path("app/bulk-catalog/{$token}/preview.json")), true);

        abort_unless(is_array($preview) && (int) ($preview['user_id'] ?? 0) === $userId, 404);

        return $preview;
    }

    private function readRows(string $path): array
    {
        $sheet = Excel::toArray([], $path)[0] ?? [];
        if (! $sheet) return [];
        $headers = array_map(fn ($value) => Str::snake(trim((string) $value)), array_shift($sheet));
        return collect($sheet)->map(function ($row, $index) use ($headers) {
            $row = array_pad($row, count($headers), null);
            $data = array_combine($headers, array_slice($row, 0, count($headers)));
            return array_map(fn ($value) => is_string($value) ? trim($value) : $value, $data) + ['_row'=>$index + 2];
        })->filter(fn ($row) => collect($row)->except('_row')->contains(fn ($value) => $value !== null && $value !== ''))->values()->all();
    }

    private function validateZip(string $path, bool $provided): array
    {
        if (! $provided) return [];
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) return ['__error__'=>'Unable to read images ZIP.'];
        $files = [];
        for ($i=0; $i<$zip->numFiles; $i++) {
            $stat = $zip->statIndex($i); $name = str_replace('\\', '/', $stat['name']); $base = basename($name);
            $extension = strtolower(pathinfo($base, PATHINFO_EXTENSION));
            if (str_contains($name, '../') || str_starts_with($name, '/') || str_ends_with($name, '/')) { $files['__error__']='Unsafe ZIP path detected.'; break; }
            if (! in_array($extension, self::IMAGE_EXTENSIONS, true)) { $files['__error__']="Unsupported image: {$base}"; break; }
            if (isset($files[$base])) { $files['__error__']="Duplicate image filename: {$base}"; break; }
            if (($stat['size'] ?? 0) > 10 * 1024 * 1024) { $files['__error__']="Image exceeds 10 MB: {$base}"; break; }
            $files[$base] = $name;
        }
        $zip->close(); return $files;
    }

    private function analyse(string $type, array $rows, array $files): array
    {
        $errors = isset($files['__error__']) ? [$files['__error__']] : []; unset($files['__error__']);
        if (! $rows) $errors[] = 'The spreadsheet has no data rows.';
        $seen=[];
        foreach ($rows as $row) if ($error=$this->rowError($type,$row,$files,$seen)) $errors[]="Row {$row['_row']}: {$error}";
        $created=0; $updated=0; $variantRows=0;
        if ($type === 'products') {
            foreach ($this->productGroups($rows) as $key=>$group) {
                $errors = array_merge($errors, $this->productGroupErrors($key,$group));
                $this->productForGroup($group) ? $updated++ : $created++;
                $variantRows += count(array_filter($group, fn ($row) => $this->variantOptions($row) !== []));
            }
        } else foreach ($rows as $row) $this->matchesExisting($type,$row) ? $updated++ : $created++;
        if ($type === 'categories') $errors = array_merge($errors,$this->categoryTreeErrors($rows));
        return compact('created','updated','errors') + ['rows'=>$rows,'image_files'=>array_keys($files),'product_groups'=>$type === 'products' ? count($this->productGroups($rows)) : 0,'variant_rows'=>$variantRows];
    }

    private function rowError(string $type, array $row, array $files, array &$seen): ?string
    {
        if (blank($row['name'] ?? null)) return 'Name is required.';
        if ($type === 'categories' && blank($row['row_key'] ?? null)) return 'row_key is required for categories.';
        if ($type === 'products' && blank($row['unit_price'] ?? null)) return 'unit_price is required for products.';
        if ($type === 'products' && blank($row['category_slug'] ?? null) && blank($row['category_id'] ?? null) && blank($row['category_path'] ?? null)) return 'category_slug, category_id, or category_path is required for products.';
        if ($type === 'products' && ! is_numeric($row['unit_price'])) return 'unit_price must be numeric.';
        $identity = match($type) {'categories'=>strtolower((string)$row['row_key']),'products'=>$this->normalise((string)(($row['sku']??null) ?: ($row['barcode']??null) ?: (($row['product_group_key']??null) ?: $row['name']))),default=>$this->normalise((string)$row['name'])};
        if (isset($seen[$identity])) return 'Duplicate identity inside the uploaded file.'; $seen[$identity]=true;
        if ($type === 'products') try { $this->variantOptions($row); } catch (\InvalidArgumentException $e) { return $e->getMessage(); }
        foreach (['cover_image_file','banner_image_file','icon_file','logo_file','thumbnail_file','meta_img_file','variant_image_file'] as $column) {
            if ($column === 'meta_img_file' && $this->isThumbnailReference($row[$column] ?? null)) continue;
            if (filled($row[$column] ?? null) && ! isset($files[$row[$column]])) return "Image file {$row[$column]} is missing from ZIP.";
        }
        foreach ($this->galleryFiles($row) as $file) if ($file !== '@thumbnail' && !isset($files[$file])) return "Gallery image {$file} is missing from ZIP.";
        if ($type === 'products' && array_key_exists('information_sections',$row)) try { $this->parseInformationSections($row); } catch (\InvalidArgumentException $e) { return $e->getMessage(); }
        return null;
    }

    private function productGroupErrors(string $key, array $rows): array
    {
        $variants=array_filter($rows,fn($row)=>$this->variantOptions($row)!==[]); if (!$variants) return [];
        if (count($variants)!==count($rows)) return ["Product group {$key} mixes simple and variant rows."];
        $errors=[];
        if (count($rows)<2) $errors[]="Product group {$key} has variant_options but needs at least two rows.";
        foreach ($rows as $row) if (blank($row['sku']??null) && blank($row['barcode']??null)) $errors[]="Row {$row['_row']}: every variant requires sku or barcode.";
        if (count(array_filter($rows,fn($row)=>$this->isDefaultVariant($row)))!==1) $errors[]="Product group {$key} must have exactly one is_default_variant=true row.";
        foreach (self::PARENT_FIELDS as $field) if (collect($rows)->pluck($field)->filter(fn($v)=>filled($v))->map(fn($v)=>trim((string)$v))->unique()->count()>1) $errors[]="Product group {$key} has conflicting {$field} values.";
        $keys=null; $combinations=[];
        foreach ($rows as $row) { $options=$this->variantOptions($row); if ($keys!==null && $keys!==array_keys($options)) {$errors[]="Product group {$key} must use the same variant option names in every row.";break;} $keys=array_keys($options); $variant=$this->variantString($options); if(isset($combinations[$variant])){$errors[]="Product group {$key} contains duplicate variant options.";break;} $combinations[$variant]=true; }
        if (collect($rows)->map(fn($row)=>$this->productMatch($row)?->id)->filter()->unique()->count()>1) $errors[]="Product group {$key} matches more than one existing product.";
        return $errors;
    }

    private function categoryTreeErrors(array $rows): array
    {
        $keys=[]; foreach($rows as $row){$key=strtolower((string)($row['row_key']??''));if(isset($keys[$key]))return["Duplicate category row_key: {$key}"];$keys[$key]=$row;}
        foreach($keys as $key=>$row){$parent=strtolower((string)($row['parent_row_key']??''));if($parent&&!isset($keys[$parent])&&!Category::query()->where('slug',$row['parent_slug']??'')->exists())return["Row {$row['_row']}: parent_row_key {$parent} is missing."];$visited=[];$current=$key;while(($parent=strtolower((string)($keys[$current]['parent_row_key']??'')))!==''){if(isset($visited[$parent]))return["Category cycle detected at {$key}."];$visited[$parent]=true;$current=$parent;if(!isset($keys[$current]))break;}}
        return [];
    }

    private function matchesExisting(string $type,array $row): bool
    {
        return match ($type) {
            'brands' => Brand::query()->get()->contains(fn ($brand) => $this->normalise($brand->name) === $this->normalise($row['name'])),
            'products' => $this->productMatch($row) !== null,
            default => false,
        };
    }
    private function persist(string $type,array $rows,string $directory,int $userId,bool $replace): array { return match($type){'categories'=>$this->persistCategories($rows,$directory,$userId),'brands'=>$this->persistBrands($rows,$directory,$userId),'products'=>$this->persistProducts($rows,$directory,$userId,$replace)}; }

    private function persistCategories(array $rows,string $directory,int $userId): array
    {
        $map = []; $result = ['created'=>0, 'updated'=>0, 'media_cleanup'=>[]]; $remaining = $rows;
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
                $category->save();
                CategoryTranslation::updateOrCreate(['category_id'=>$category->id, 'lang'=>env('DEFAULT_LANGUAGE','en')], ['name'=>$category->name]);
                $map[strtolower($row['row_key'])] = $category; unset($remaining[$index]);
                $new ? $result['created']++ : $result['updated']++; $progress = true;
            }
            if (! $progress) throw new \RuntimeException('Category parents could not be resolved.');
        }
        return $result;
    }

    private function persistBrands(array $rows,string $directory,int $userId): array
    {
        $result=['created'=>0,'updated'=>0,'media_cleanup'=>[]];foreach($rows as$row){$brand=Brand::query()->get()->first(fn($item)=>$this->normalise($item->name)===$this->normalise($row['name']));$new=!$brand;$brand??=new Brand();$brand->name=$row['name'];$brand->slug=filled($row['slug']??null)?Str::slug($row['slug']):($brand->slug?:Str::slug($row['name']).'-'.Str::random(5));foreach(['meta_title','meta_description']as$field)if(filled($row[$field]??null))$brand->{$field}=$row[$field];if(filled($row['logo_file']??null))$brand->logo=$this->storeImage($directory,$row['logo_file'],$userId);$brand->save();\App\Models\BrandTranslation::updateOrCreate(['brand_id'=>$brand->id,'lang'=>env('DEFAULT_LANGUAGE','en')],['name'=>$brand->name]);$new?$result['created']++:$result['updated']++;}return$result;
    }

    private function persistProducts(array $rows,string $directory,int $userId,bool $replace): array
    {
        $result=['created'=>0,'updated'=>0,'media_cleanup'=>[]];
        foreach($this->productGroups($rows)as$group){$product=$this->productForGroup($group);$new=!$product;$product??=new Product();$parent=$this->parentRow($group);$default=$this->defaultRow($group);$oldMedia=!$new&&$replace&&$this->hasNewProductImages($group)?$this->replacementMediaIds($product,$group):[];if($new){$product->attributes='[]';$product->choice_options='[]';$product->colors='[]';}$category=$this->categoryFromRow($parent,true);$brand=$this->brandFromRow($parent);foreach(['name','description','unit','tags','meta_title','meta_description','est_shipping_days','video_provider','video_link']as$field)if(filled($parent[$field]??null))$product->{$field}=$parent[$field];$product->category_id=$category?->id??$product->category_id;$product->brand_id=$brand?->id??$product->brand_id;$product->added_by='admin';$product->user_id=$product->user_id?:$userId;$product->approved=1;$product->slug=filled($parent['slug']??null)?Str::slug($parent['slug']):($product->slug?:Str::slug($parent['name']).'-'.Str::random(5));$product->unit_price=(float)$default['unit_price'];if(filled($default['barcode']??null))$product->barcode=$default['barcode'];if($replace)$this->clearReplacedParentImageSlots($product,$parent);$this->applyParentImages($product,$parent,$directory,$userId);$product->save();if($this->variantOptions($default)!==[])$this->persistVariantStocks($product,$group,$directory,$userId);else$this->persistSimpleStock($product,$default,$directory,$userId);DB::table('product_categories')->updateOrInsert(['product_id'=>$product->id,'category_id'=>$product->category_id]);\App\Models\ProductTranslation::updateOrCreate(['product_id'=>$product->id,'lang'=>env('DEFAULT_LANGUAGE','en')],['name'=>$product->name,'unit'=>$product->unit,'description'=>$product->description]);if(array_key_exists('information_sections',$parent)&&filled($parent['information_sections']))app(ProductInformationSectionService::class)->replaceFromBulk($product,$this->parseInformationSections($parent));$result['media_cleanup']=array_merge($result['media_cleanup'],$oldMedia);$new?$result['created']++:$result['updated']++;}
        return $result;
    }

    /** @return array<int, int> */
    private function replacementMediaIds(Product $product, array $rows): array
    {
        $parent = $this->parentRow($rows);
        $ids = [];

        if (filled($parent['thumbnail_file'] ?? null)) $ids[] = $product->thumbnail_img;
        if (filled($parent['meta_img_file'] ?? null)) $ids[] = $product->meta_img;
        if (filled($parent['gallery_files'] ?? null)) $ids = [...$ids, ...explode(',', (string) $product->photos)];

        foreach ($rows as $row) {
            if (! filled($row['variant_image_file'] ?? null)) continue;
            $stock = $this->stockForRow($product, $row) ?? $product->stocks()->where('variant', $this->variantString($this->variantOptions($row)))->first();
            if ($stock) $ids[] = $stock->image;
        }

        return collect($ids)->filter(fn ($id) => is_numeric($id) && (int) $id > 0)->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    private function clearReplacedParentImageSlots(Product $product, array $parent): void
    {
        if (filled($parent['thumbnail_file'] ?? null)) $product->thumbnail_img = null;
        if (filled($parent['meta_img_file'] ?? null)) $product->meta_img = null;
        if (filled($parent['gallery_files'] ?? null)) $product->photos = null;
    }

    private function persistSimpleStock(Product $product,array $row,string $directory,int $userId): void
    {
        $stock = $this->stockForRow($product, $row) ?? $product->stocks()->where('variant', '')->first() ?? new ProductStock(['variant' => '']);
        $isNewStock = ! $stock->exists;
        $stock->product_id = $product->id;
        $stock->variant = '';
        if (filled($row['sku'] ?? null)) $stock->sku = $row['sku'];
        if (filled($row['barcode'] ?? null)) $stock->barcode = $row['barcode'];
        elseif ($isNewStock) $stock->barcode = null;
        $stock->price = (float) $row['unit_price'];
        $stock->qty = filled($row['qty'] ?? null) ? (int) $row['qty'] : ($stock->qty ?? 0);
        if (filled($row['variant_image_file'] ?? null)) $stock->image = $this->storeImage($directory, $row['variant_image_file'], $userId);
        $stock->save();
    }

    private function persistVariantStocks(Product $product,array $rows,string $directory,int $userId): void
    {
        $optionsByRow=array_map(fn($row)=>$this->variantOptions($row),$rows);$attributeIds=[];
        foreach(array_keys($optionsByRow[0])as$name){$attribute=Attribute::query()->get()->first(fn(Attribute $item)=>$this->normalise((string)$item->name)===$this->normalise($name));if(! $attribute){$attribute=new Attribute();$attribute->name=$name;$attribute->save();}AttributeTranslation::updateOrCreate(['attribute_id'=>$attribute->id,'lang'=>env('DEFAULT_LANGUAGE','en')],['name'=>$attribute->name]);$attributeIds[$name]=$attribute->id;}
        $choiceOptions=[];foreach($attributeIds as$name=>$id){$values=collect($optionsByRow)->pluck($name)->unique()->values()->all();foreach($values as$value){if(!AttributeValue::query()->where('attribute_id',$id)->where('value',$value)->exists()){$attributeValue=new AttributeValue();$attributeValue->attribute_id=$id;$attributeValue->value=$value;$attributeValue->save();}}$choiceOptions[]=['attribute_id'=>$id,'values'=>$values];}$product->attributes=json_encode(array_values($attributeIds));$product->choice_options=json_encode($choiceOptions,JSON_UNESCAPED_UNICODE);$product->variant_product=1;$product->save();
        foreach($rows as$row){$options=$this->variantOptions($row);$stock=$this->stockForRow($product,$row)??$product->stocks()->where('variant',$this->variantString($options))->first()??new ProductStock();$isNewStock=!$stock->exists;$stock->product_id=$product->id;$stock->variant=$this->variantString($options);if(filled($row['sku']??null))$stock->sku=$row['sku'];if(filled($row['barcode']??null))$stock->barcode=$row['barcode'];elseif($isNewStock)$stock->barcode=null;$stock->price=(float)$row['unit_price'];$stock->qty=filled($row['qty']??null)?(int)$row['qty']:($stock->qty??0);if(filled($row['variant_image_file']??null))$stock->image=$this->storeImage($directory,$row['variant_image_file'],$userId);$stock->save();}
    }

    private function applyParentImages(Product $product,array $row,string $directory,int $userId): void
    {
        if(filled($row['thumbnail_file']??null))$product->thumbnail_img=$this->storeImage($directory,$row['thumbnail_file'],$userId);if(filled($row['meta_img_file']??null))$product->meta_img=$this->isThumbnailReference($row['meta_img_file'])?$product->thumbnail_img:$this->storeImage($directory,$row['meta_img_file'],$userId);if(filled($row['gallery_files']??null))$product->photos=collect($this->galleryFiles($row))->map(fn($file)=>$file==='@thumbnail'?$product->thumbnail_img:$this->storeImage($directory,$file,$userId))->filter()->unique()->implode(',');
    }

    public function parseInformationSections(array $row): array
    {
        $raw = trim((string) ($row['information_sections'] ?? ''));
        if ($raw === '') return [];
        try { $sections = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new \InvalidArgumentException('information_sections must be a valid JSON array.'); }
        if (! is_array($sections) || ! array_is_list($sections)) throw new \InvalidArgumentException('information_sections must be a JSON array.');
        $lang = env('DEFAULT_LANGUAGE', 'en');
        return collect($sections)->values()->map(function ($section, $index) use ($lang) {
            if (! is_array($section)) throw new \InvalidArgumentException('Each information section must be an object.');
            $title = trim((string) ($section['title'] ?? ''));
            $content = (string) ($section['content'] ?? '');
            if ($title === '' || trim(strip_tags($content)) === '') throw new \InvalidArgumentException('Each information section requires title and content.');
            if (mb_strlen($title) > 255) throw new \InvalidArgumentException('An information section title may not exceed 255 characters.');
            $translations = [$lang => ['title'=>$title, 'content'=>$content]];
            if (isset($section['translations']) && ! is_array($section['translations'])) throw new \InvalidArgumentException('Information section translations must be an object.');
            foreach (($section['translations'] ?? []) as $translationLanguage => $translation) {
                if (! is_array($translation)) throw new \InvalidArgumentException('Each information section translation must be an object.');
                $translatedTitle = trim((string) ($translation['title'] ?? ''));
                $translatedContent = (string) ($translation['content'] ?? '');
                if (trim((string) $translationLanguage) === '' || $translatedTitle === '' || trim(strip_tags($translatedContent)) === '') throw new \InvalidArgumentException('Each information section translation requires language, title and content.');
                $translations[trim($translationLanguage)] = ['title'=>$translatedTitle, 'content'=>$translatedContent];
            }
            $active = filter_var($section['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($active === null) throw new \InvalidArgumentException('Information section is_active must be true or false.');
            return ['sort_order'=>is_numeric($section['sort_order'] ?? null) ? (int) $section['sort_order'] : $index + 1, 'is_active'=>$active, 'translations'=>$translations];
        })->all();
    }

    private function productGroups(array $rows): array {$groups=[];foreach($rows as$row){$key=filled($row['product_group_key']??null)?'group:'.$this->normalise((string)$row['product_group_key']):'row:'.$row['_row'];$groups[$key][]=$row;}return$groups;}
    private function productForGroup(array $rows):?Product{return collect($rows)->map(fn($row)=>$this->productMatch($row))->filter()->first();}
    private function parentRow(array $rows):array{$parent=$this->defaultRow($rows);foreach(self::PARENT_FIELDS as$field){if(filled($parent[$field]??null))continue;foreach($rows as$row)if(filled($row[$field]??null)){$parent[$field]=$row[$field];break;}}return$parent;}
    private function defaultRow(array $rows):array{foreach($rows as$row)if($this->isDefaultVariant($row))return$row;return$rows[0];}
    private function productMatch(array $row):?Product{if(filled($row['sku']??null)&&($stock=ProductStock::query()->with('product')->where('sku',$row['sku'])->first()))return$stock->product;$identity=app(ProductIdentityLookupService::class);if(filled($row['barcode']??null)&&($found=$identity->find((string)$row['barcode'])))return$found['product'];if(filled($row['slug']??null)&&($product=Product::query()->where('slug',Str::slug($row['slug']))->first()))return$product;if(blank($row['sku']??null)&&blank($row['barcode']??null)&&($category=$this->categoryFromRow($row)))return Product::query()->where('category_id',$category->id)->get()->first(fn($item)=>$this->normalise($item->name)===$this->normalise($row['name']));return null;}
    private function stockForRow(Product $product,array $row):?ProductStock{if(filled($row['sku']??null)&&($stock=$product->stocks()->where('sku',$row['sku'])->first()))return$stock;if(filled($row['barcode']??null)&&($stock=$product->stocks()->where('barcode',$row['barcode'])->first()))return$stock;return null;}
    private function categoryFromRow(array $row,bool $createPath=false):?Category{if(filled($row['category_slug']??null))return Category::query()->where('slug',$row['category_slug'])->first();if(filled($row['category_id']??null))return Category::find($row['category_id']);return filled($row['category_path']??null)?$this->categoryFromPath((string)$row['category_path'],$createPath):null;}
    private function categoryFromPath(string $path,bool $create):?Category{$segments=collect(explode('>',$path))->map(fn($s)=>trim($s))->filter()->values();if($segments->isEmpty())return null;$parentId=0;$category=null;foreach($segments as$level=>$name){$category=Category::query()->where('parent_id',$parentId)->get()->first(fn($item)=>$this->normalise($item->name)===$this->normalise($name));if(!$category&&!$create)return null;if(!$category){$category=new Category();$category->name=$name;$category->parent_id=$parentId;$category->level=$level;$category->order_level=0;$category->active=1;$category->slug=Str::slug($name).'-'.Str::random(5);$category->save();CategoryTranslation::updateOrCreate(['category_id'=>$category->id,'lang'=>env('DEFAULT_LANGUAGE','en')],['name'=>$name]);}$parentId=$category->id;}return$category;}
    private function brandFromRow(array $row):?Brand{if(filled($row['brand_slug']??null))return Brand::query()->where('slug',$row['brand_slug'])->first();return filled($row['brand_id']??null)?Brand::find($row['brand_id']):null;}
    private function variantOptions(array $row): array
    {
        $raw = trim((string) ($row['variant_options'] ?? ''));
        if ($raw === '') return [];
        try { $options = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new \InvalidArgumentException('variant_options must be a valid JSON object.'); }
        if (! is_array($options) || array_is_list($options) || $options === []) throw new \InvalidArgumentException('variant_options must be a non-empty JSON object.');
        $normalised = [];
        foreach ($options as $name => $value) {
            $name = trim((string) $name); $value = trim((string) $value);
            if ($name === '' || $value === '') throw new \InvalidArgumentException('variant_options requires non-empty option names and values.');
            $normalised[$name] = $value;
        }
        return $normalised;
    }
    private function variantString(array $options):string{return collect($options)->map(fn($value)=>str_replace(' ','',$value))->implode('-');}
    private function isDefaultVariant(array $row):bool{return filter_var($row['is_default_variant']??false,FILTER_VALIDATE_BOOLEAN);}
    private function replacementImageErrors(array $rows): array
    {
        $errors = [];
        foreach ($this->productGroups($rows) as $key => $group) {
            $parent = $this->parentRow($group);
            if (! $this->hasNewParentImages($parent)) continue;
            if ((filled($parent['meta_img_file'] ?? null) && $this->isThumbnailReference($parent['meta_img_file'])) || in_array('@thumbnail', $this->galleryFiles($parent), true)) {
                if (blank($parent['thumbnail_file'] ?? null)) $errors[] = "Product group {$key} uses @thumbnail while replacing images but has no new thumbnail_file.";
            }
        }
        return $errors;
    }
    private function hasNewParentImages(array $row):bool{return filled($row['thumbnail_file']??null)||(filled($row['meta_img_file']??null)&&!$this->isThumbnailReference($row['meta_img_file']))||filled($row['gallery_files']??null);}
    private function hasNewProductImages(array $rows):bool{return collect($rows)->contains(fn($row)=>filled($row['thumbnail_file']??null)||(filled($row['meta_img_file']??null)&&!$this->isThumbnailReference($row['meta_img_file']))||filled($row['gallery_files']??null)||filled($row['variant_image_file']??null));}
    private function galleryFiles(array $row):array{return collect(explode(';',(string)($row['gallery_files']??'')))->map(fn($file)=>trim($file))->filter()->map(fn($file)=>$this->isThumbnailReference($file)?'@thumbnail':$file)->values()->all();}
    private function isThumbnailReference(mixed $value):bool{return is_string($value)&&strtolower(trim($value))==='@thumbnail';}
    private function storeImage(string $directory,string $name,int $userId): int
    {
        $key = "{$directory}:{$userId}:{$name}";
        if (isset($this->storedImages[$key])) return $this->storedImages[$key];
        $zip = new ZipArchive(); $zip->open("{$directory}/images.zip"); $content = $zip->getFromName($name); $zip->close();
        if ($content === false) throw new \RuntimeException("Image {$name} was not found.");
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $path = 'uploads/all/'.Str::random(40).'.'.$extension;
        File::ensureDirectoryExists(public_path('uploads/all')); file_put_contents(public_path($path), $content);
        return $this->storedImages[$key] = Upload::create(['file_original_name'=>pathinfo($name,PATHINFO_FILENAME),'file_name'=>$path,'user_id'=>$userId,'extension'=>$extension,'type'=>'image','file_size'=>strlen($content)])->id;
    }
    private function normalise(string $value):string{return preg_replace('/\s+/u',' ',mb_strtolower(trim($value)));}
}
