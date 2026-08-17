<?php

namespace Tests\Feature;

use App\Http\Resources\V2\ProductDetailCollection;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductTranslation;
use App\Models\User;
use App\Services\BulkCatalogImportService;
use App\Services\ProductInformationSectionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ProductInformationSectionsRuntimeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_sections_work_with_real_database_relations_storefront_api_copy_and_cascade(): void
    {
        $this->assertTrue(Schema::hasTable('product_information_sections'));
        $this->assertTrue(Schema::hasTable('product_information_section_translations'));

        $user = $this->user();
        $product = $this->product($user, 'runtime-sections');
        $sections = app(ProductInformationSectionService::class);

        $this->assertSame('Runtime description', $product->getTranslation('description', 'en'));
        $this->assertCount(0, $product->informationSections);

        $sections->replaceFromBulk($product, [
            [
                'sort_order' => 2,
                'is_active' => true,
                'translations' => [
                    'en' => ['title' => 'Ingredients', 'content' => '<p>Chicken</p>'],
                    'fr' => ['title' => 'Ingrédients', 'content' => '<p>Poulet</p>'],
                ],
            ],
            [
                'sort_order' => 1,
                'is_active' => false,
                'translations' => [
                    'en' => ['title' => 'Feeding Guide', 'content' => '<p>Daily</p>'],
                ],
            ],
        ]);

        $product->refresh()->load('informationSections.translations');
        $this->assertSame('Feeding Guide', $product->informationSections->first()->getTranslation('title', 'ar'));
        $this->assertSame('Ingredients', $product->informationSections->last()->getTranslation('title', 'en'));

        App::setLocale('fr');
        $storefront = view('frontend.product_details.description', ['detailedProduct' => $product])->render();
        App::setLocale('en');
        $this->assertStringContainsString('Ingrédients', $storefront);
        $this->assertStringNotContainsString('Feeding Guide', $storefront);

        $api = (new ProductDetailCollection(collect([$product])))->resolve();
        $this->assertSame('Runtime description', $api['data'][0]['description']);
        $this->assertCount(1, $api['data'][0]['information_sections']);
        $this->assertSame('Ingredients', $api['data'][0]['information_sections'][0]['title']);

        $apiResponse = $this->getJson('/api/v2/products/'.$product->slug.'/0');
        $apiResponse->assertOk()->assertJsonPath('data.0.description', 'Runtime description');
        $this->assertSame('Ingredients', $apiResponse->json('data.0.information_sections.0.title'));

        $copy = $product->replicate();
        $copy->slug = 'runtime-sections-copy-'.Str::random(6);
        $copy->save();
        $sections->duplicate($product, $copy);
        $this->assertCount(2, $copy->fresh()->informationSections);
        $this->assertNotSame($product->informationSections->first()->id, $copy->fresh()->informationSections->first()->id);

        $sectionIds = $product->informationSections->pluck('id');
        $product->delete();
        $this->assertDatabaseMissing('product_information_sections', ['id' => $sectionIds->first()]);
        $this->assertDatabaseMissing('product_information_section_translations', ['product_information_section_id' => $sectionIds->last()]);
    }

    public function test_bulk_catalog_updates_preserves_and_clears_information_sections_on_real_database(): void
    {
        $user = $this->user();
        $product = $this->product($user, 'bulk-runtime');
        $service = app(BulkCatalogImportService::class);

        $this->confirmProducts($service, $user->id, [[
            'name' => $product->name,
            'sku' => 'SKU-bulk-runtime',
            'category_id' => 1,
            'unit_price' => 12.50,
            'unit' => 'pc',
            'qty' => 7,
            'information_sections' => json_encode([[
                'title' => 'Specifications',
                'content' => '<p>First content</p>',
                'sort_order' => 3,
                'is_active' => true,
                'translations' => ['fr' => ['title' => 'Spécifications', 'content' => '<p>Premier</p>']],
            ]], JSON_UNESCAPED_UNICODE),
        ]]);

        $product->refresh()->load('informationSections.translations');
        $this->assertSame('Specifications', $product->informationSections->first()->getTranslation('title', 'en'));
        $this->assertSame('Spécifications', $product->informationSections->first()->getTranslation('title', 'fr'));

        $this->confirmProducts($service, $user->id, [[
            'name' => $product->name,
            'sku' => 'SKU-bulk-runtime',
            'category_id' => 1,
            'unit_price' => 13,
            'unit' => 'pc',
            'qty' => 8,
        ]]);
        $this->assertCount(1, $product->fresh()->informationSections);

        $this->confirmProducts($service, $user->id, [[
            'name' => $product->name,
            'sku' => 'SKU-bulk-runtime',
            'category_id' => 1,
            'unit_price' => 13.50,
            'unit' => 'pc',
            'qty' => 8,
            'information_sections' => '',
        ]]);
        $this->assertCount(1, $product->fresh()->informationSections);

        $this->confirmProducts($service, $user->id, [[
            'name' => $product->name,
            'sku' => 'SKU-bulk-runtime',
            'category_id' => 1,
            'unit_price' => 14,
            'unit' => 'pc',
            'qty' => 9,
            'information_sections' => '[]',
        ]]);
        $this->assertCount(0, $product->fresh()->informationSections);
    }

    public function test_bulk_catalog_creates_a_single_product_with_weight_variants(): void
    {
        $user = $this->user();
        $service = app(BulkCatalogImportService::class);
        $slug = 'generic-dog-food-'.Str::random(8);
        $rows = [
            [
                'product_group_key' => 'generic-dog-food',
                'name' => 'Generic Dog Food',
                'sku' => 'DOG-3KG-'.Str::random(5),
                'category_path' => 'Test Pets > Dry Food',
                'variant_options' => '{"Weight":"3KG"}',
                'is_default_variant' => 'true',
                'unit_price' => 18.50,
                'unit' => 'bag',
                'qty' => 0,
                'slug' => $slug,
                'description' => 'A generic product description.',
            ],
            [
                'product_group_key' => 'generic-dog-food',
                'name' => 'Generic Dog Food',
                'sku' => 'DOG-10KG-'.Str::random(5),
                'category_path' => 'Test Pets > Dry Food',
                'variant_options' => '{"Weight":"10KG"}',
                'unit_price' => 45,
                'unit' => 'bag',
                'qty' => 0,
                'slug' => $slug,
                'description' => 'A generic product description.',
            ],
        ];

        $headers = array_values(array_unique(array_merge(...array_map('array_keys', $rows))));
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->fromArray($headers, null, 'A1');
        foreach ($rows as $index => $row) {
            $sheet->fromArray(array_map(fn ($header) => $row[$header] ?? null, $headers), null, 'A'.($index + 2));
        }
        $path = storage_path('app/testing-bulk-'.Str::uuid().'.xlsx');
        (new Xlsx($book))->save($path);
        $upload = UploadedFile::fake()->createWithContent('products.xlsx', (string) file_get_contents($path));
        @unlink($path);

        $preview = $service->preview('products', $upload, null, $user->id);
        $this->assertSame([], $preview['errors']);
        $this->assertSame(1, $preview['created']);
        $this->assertSame(2, $preview['variant_rows']);
        $service->confirm($preview['token'], $user->id);

        $product = Product::query()->where('name', 'Generic Dog Food')->firstOrFail();
        $this->assertSame(1, $product->variant_product);
        $this->assertSame(18.50, (float) $product->unit_price);
        $this->assertCount(2, $product->stocks);
        $this->assertSame(['Weight'], array_map(fn ($choice) => \App\Models\Attribute::find($choice->attribute_id)->name, $product->choiceOptionsArray()));
    }

    private function confirmProducts(BulkCatalogImportService $service, int $userId, array $rows): void
    {
        $headers = array_values(array_unique(array_merge(...array_map('array_keys', $rows))));
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->fromArray($headers, null, 'A1');
        foreach ($rows as $index => $row) {
            $sheet->fromArray(array_map(fn ($header) => $row[$header] ?? null, $headers), null, 'A'.($index + 2));
        }

        $path = storage_path('app/testing-bulk-'.Str::uuid().'.xlsx');
        (new Xlsx($book))->save($path);
        $upload = UploadedFile::fake()->createWithContent('products.xlsx', (string) file_get_contents($path));
        @unlink($path);

        $preview = $service->preview('products', $upload, null, $userId);
        $this->assertSame([], $preview['errors']);
        $result = $service->confirm($preview['token'], $userId);
        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['updated']);
    }

    private function user(): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Sections Runtime '.Str::random(8),
            'email' => Str::lower(Str::random(16)).'@example.test',
            'password' => Hash::make('SectionsPassword123!'),
            'user_type' => 'admin',
            'banned' => 0,
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::query()->findOrFail($id);
    }

    private function product(User $user, string $suffix): Product
    {
        $product = Product::query()->create([
            'name' => 'Runtime '.$suffix,
            'added_by' => 'admin',
            'user_id' => $user->id,
            'category_id' => 1,
            'unit_price' => 10,
            'unit' => 'pc',
            'tags' => 'runtime',
            'description' => 'Runtime description',
            'slug' => $suffix.'-'.Str::random(8),
        ]);
        ProductTranslation::query()->create([
            'product_id' => $product->id,
            'lang' => 'en',
            'name' => $product->name,
            'unit' => 'pc',
            'description' => 'Runtime description',
        ]);
        ProductStock::query()->create([
            'product_id' => $product->id,
            'variant' => '',
            'sku' => 'SKU-'.$suffix,
            'price' => 10,
            'qty' => 5,
        ]);

        return $product->fresh();
    }
}
