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

    private function workbook(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'bulk-catalog-').'.xlsx';
        $sheet = new Spreadsheet(); $sheet->getActiveSheet()->fromArray($rows);
        (new Xlsx($sheet))->save($path);
        return new UploadedFile($path, 'catalog.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
