<?php

namespace App\Http\Controllers;

use App\Models\ProductsExport;
use App\Services\BulkCatalogImportService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class BulkCatalogImportController extends Controller
{
    public function __construct(private BulkCatalogImportService $imports)
    {
        $this->middleware('super_admin');
    }

    public function index()
    {
        return view('backend.product.bulk_catalog.index');
    }

    public function template(string $type)
    {
        abort_unless(in_array($type, ['categories', 'brands', 'products'], true), 404);
        $headers = match ($type) {
            'categories' => ['row_key', 'parent_row_key', 'name', 'slug', 'meta_title', 'meta_description', 'order_level', 'cover_image_file', 'banner_image_file', 'icon_file'],
            'brands' => ['name', 'slug', 'meta_title', 'meta_description', 'logo_file'],
            default => ['name', 'sku', 'barcode', 'category_slug', 'category_id', 'brand_slug', 'brand_id', 'unit_price', 'unit', 'qty', 'slug', 'description', 'tags', 'meta_title', 'meta_description', 'thumbnail_file', 'gallery_files', 'information_sections'],
        };
        $book = new Spreadsheet();
        $book->getActiveSheet()->fromArray($headers, null, 'A1');
        return response()->streamDownload(fn () => (new Xlsx($book))->save('php://output'), "{$type}-bulk-import-template.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function preview(Request $request)
    {
        if ($request->hasFile('images_zip') && ! extension_loaded('zip')) {
            return back()->withInput()->withErrors(['images_zip' => translate('Please enable the Zip extension.')]);
        }
        $data = $request->validate([
            'type' => ['required', 'in:categories,brands,products'],
            'spreadsheet' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:20480'],
            'images_zip' => ['nullable', 'file', 'mimes:zip', 'max:102400'],
        ]);

        try {
            $preview = $this->imports->preview($data['type'], $request->file('spreadsheet'), $request->file('images_zip'), (int) $request->user()->id);
        } catch (\Throwable $exception) {
            report($exception);
            return back()->withInput()->withErrors(['spreadsheet' => translate('The import file could not be read.')]);
        }

        return view('backend.product.bulk_catalog.preview', compact('preview'));
    }

    public function confirm(Request $request)
    {
        $request->validate(['token' => ['required', 'uuid']]);
        try {
            $result = $this->imports->confirm($request->token, (int) $request->user()->id);
        } catch (\Throwable $exception) {
            report($exception);
            return back()->withErrors(['token' => translate('The import could not be completed. No partial records were saved.')]);
        }
        if (! empty($result['errors'])) return back()->withErrors(['token' => implode(' ', $result['errors'])]);
        return redirect()->route('bulk-catalog.index')->with('success', "{$result['created']} created, {$result['updated']} updated.");
    }

    public function exportProducts()
    {
        return Excel::download(new ProductsExport(), 'bulk-catalog-products.xlsx');
    }
}
