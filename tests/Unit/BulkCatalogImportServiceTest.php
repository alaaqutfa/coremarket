<?php

namespace Tests\Unit;

use App\Services\BulkCatalogImportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class BulkCatalogImportServiceTest extends TestCase
{
    public function test_product_information_sections_json_supports_order_status_and_translations(): void
    {
        $sections = app(BulkCatalogImportService::class)->parseInformationSections([
            'information_sections' => json_encode([
                [
                    'title' => 'Ingredients',
                    'content' => '<p>Chicken</p>',
                    'sort_order' => 4,
                    'is_active' => false,
                    'translations' => [
                        'ar' => ['title' => 'المكونات', 'content' => '<p>دجاج</p>'],
                    ],
                ],
            ]),
        ]);

        $this->assertSame(4, $sections[0]['sort_order']);
        $this->assertFalse($sections[0]['is_active']);
        $this->assertSame('Ingredients', $sections[0]['translations']['en']['title']);
        $this->assertSame('المكونات', $sections[0]['translations']['ar']['title']);
    }

    public function test_product_information_sections_json_rejects_missing_content(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(BulkCatalogImportService::class)->parseInformationSections([
            'information_sections' => '[{"title":"Ingredients","content":""}]',
        ]);
    }

    public function test_category_preview_accepts_unlimited_nested_rows_without_writing_records(): void
    {
        $file = $this->workbook([
            ['row_key', 'parent_row_key', 'name'],
            ['root', '', 'Root'], ['child', 'root', 'Child'], ['grandchild', 'child', 'Grandchild'],
        ]);
        $preview = app(BulkCatalogImportService::class)->preview('categories', $file, null, 999);
        $this->assertSame(3, $preview['created']);
        $this->assertSame([], $preview['errors']);
        File::deleteDirectory(storage_path('app/bulk-catalog/'.$preview['token']));
    }

    public function test_category_preview_rejects_cycles(): void
    {
        $cycle = $this->workbook([['row_key', 'parent_row_key', 'name'], ['one', 'two', 'One'], ['two', 'one', 'Two']]);
        $preview = app(BulkCatalogImportService::class)->preview('categories', $cycle, null, 999);
        $this->assertNotEmpty($preview['errors']);
        File::deleteDirectory(storage_path('app/bulk-catalog/'.$preview['token']));
    }

    public function test_brand_preview_accepts_the_brand_template_without_product_identity_columns(): void
    {
        $file = $this->workbook([
            ['name', 'slug', 'meta_title', 'meta_description', 'logo_file'],
            ['Genesis', 'genesis', 'Genesis', 'Premium pet nutrition', ''],
        ]);

        $preview = app(BulkCatalogImportService::class)->preview('brands', $file, null, 999);

        $this->assertSame(1, $preview['created']);
        $this->assertSame([], $preview['errors']);
        File::deleteDirectory(storage_path('app/bulk-catalog/'.$preview['token']));
    }

    public function test_thumbnail_reference_is_recognised_without_a_zip_filename(): void
    {
        $service = app(BulkCatalogImportService::class);
        $method = new \ReflectionMethod($service, 'isThumbnailReference');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($service, '@THUMBNAIL'));
        $this->assertFalse($method->invoke($service, 'detail.webp'));
    }

    private function workbook(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'bulk-catalog-').'.xlsx';
        $sheet = new Spreadsheet(); $sheet->getActiveSheet()->fromArray($rows);
        (new Xlsx($sheet))->save($path);
        return new UploadedFile($path, 'catalog.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
