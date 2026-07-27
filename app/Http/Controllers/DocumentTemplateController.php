<?php

namespace App\Http\Controllers;

use App\Models\DocumentTemplate;
use App\Models\Product;
use App\Services\CoreMarketDocumentTemplateService;
use App\Services\CoreMarketProductPricingService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use PDF;

class DocumentTemplateController extends Controller
{
    public function index(CoreMarketDocumentTemplateService $templates): View
    {
        $this->authorizePermission('document_templates.view');
        $templates->ensureDefaultTemplates();

        return view('backend.operations.document-templates.index', [
            'templates' => DocumentTemplate::query()->orderBy('template_type')->orderByDesc('is_default')->get(),
        ]);
    }

    public function create(CoreMarketDocumentTemplateService $templates): View
    {
        $this->authorizePermission('document_templates.manage');

        return $this->form(new DocumentTemplate(), $templates);
    }

    public function store(Request $request, CoreMarketDocumentTemplateService $templates): RedirectResponse
    {
        $this->authorizePermission('document_templates.manage');
        $template = DocumentTemplate::query()->create($this->validatedPayload($request, $templates));
        if ($request->boolean('is_default')) {
            $templates->setDefault($template);
        }

        return redirect()->route('operations.document-templates.edit', $template)->with('success', 'Document template created.');
    }

    public function edit(DocumentTemplate $documentTemplate, CoreMarketDocumentTemplateService $templates): View
    {
        $this->authorizePermission('document_templates.manage');

        return $this->form($documentTemplate, $templates);
    }

    public function update(Request $request, DocumentTemplate $documentTemplate, CoreMarketDocumentTemplateService $templates): RedirectResponse
    {
        $this->authorizePermission('document_templates.manage');
        $documentTemplate->update($this->validatedPayload($request, $templates, $documentTemplate));
        if ($request->boolean('is_default')) {
            $templates->setDefault($documentTemplate->fresh());
        }

        return back()->with('success', 'Document template updated.');
    }

    public function preview(DocumentTemplate $documentTemplate, CoreMarketDocumentTemplateService $templates)
    {
        $this->authorizePermission('document_templates.preview');
        abort_unless($documentTemplate->is_active, 404);
        $snapshot = $templates->templateSettingsSnapshot($documentTemplate);
        $contents = PDF::loadView('backend.operations.pdf.document-template-preview', [
            'template' => $snapshot,
            'preview' => $templates->renderPreviewData($documentTemplate->template_type),
        ], [], $templates->paperConfig($snapshot))->output();

        return response($contents, 200, ['Content-Type' => 'application/pdf']);
    }

    public function setDefault(DocumentTemplate $documentTemplate, CoreMarketDocumentTemplateService $templates): RedirectResponse
    {
        $this->authorizePermission('document_templates.manage');
        $templates->setDefault($documentTemplate);

        return back()->with('success', 'Default template updated.');
    }

    public function toggle(DocumentTemplate $documentTemplate): RedirectResponse
    {
        $this->authorizePermission('document_templates.manage');
        if ($documentTemplate->is_default && $documentTemplate->is_active) {
            return back()->withErrors(['template' => 'Set another default before deactivating this template.']);
        }
        $documentTemplate->update(['is_active' => ! $documentTemplate->is_active]);

        return back()->with('success', 'Template status updated.');
    }

    public function labels(CoreMarketDocumentTemplateService $templates): View
    {
        $this->authorizePermission('document_templates.preview');

        return view('backend.operations.labels.index', [
            'products' => Product::query()->with('stocks')->orderBy('name')->limit(500)->get(),
            'priceTemplates' => $templates->activeTemplates('price_label'),
            'barcodeTemplates' => $templates->activeTemplates('barcode_label'),
        ]);
    }

    public function labelPdf(
        Request $request,
        CoreMarketDocumentTemplateService $templates,
        CoreMarketProductPricingService $pricing
    ) {
        $this->authorizePermission('document_templates.preview');
        $data = $request->validate([
            'template_type' => ['required', 'in:price_label,barcode_label'],
            'template_id' => ['nullable', 'integer'],
            'product_ids' => ['required', 'array', 'min:1', 'max:100'],
            'product_ids.*' => ['integer', 'exists:products,id'],
        ]);
        $template = $templates->resolveTemplate($data['template_type'], isset($data['template_id']) ? (int) $data['template_id'] : null);
        $snapshot = $templates->templateSettingsSnapshot($template);
        $products = Product::query()->with(['stocks', 'productFamily', 'productSubFamily'])
            ->whereIn('id', $data['product_ids'])->orderBy('name')->get()
            ->map(function (Product $product) use ($pricing) {
                $stock = $product->stocks->first();

                return [
                    'name' => $product->name,
                    'sku' => $stock?->sku,
                    'barcode' => $stock?->barcode ?: $product->barcode,
                    'family' => $product->productFamily?->name,
                    'regular_price' => (float) $product->unit_price,
                    'sale_price' => $pricing->configuredSalePrice($product),
                ];
            });
        $contents = PDF::loadView('backend.operations.pdf.product-labels', [
            'template' => $snapshot,
            'products' => $products,
        ], [], $templates->paperConfig($snapshot))->output();

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$data['template_type'].'s.pdf"',
        ]);
    }

    private function form(DocumentTemplate $template, CoreMarketDocumentTemplateService $templates): View
    {
        return view('backend.operations.document-templates.form', [
            'template' => $template,
            'types' => $templates->typeOptions(),
            'paperTypes' => $templates->paperOptions(),
            'allowedColumns' => $templates->allowedColumns(),
        ]);
    }

    private function validatedPayload(
        Request $request,
        CoreMarketDocumentTemplateService $templates,
        ?DocumentTemplate $template = null
    ): array {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'alpha_dash', 'max:100', 'unique:document_templates,code,'.($template?->id ?? 'NULL')],
            'template_type' => ['required', 'in:'.implode(',', CoreMarketDocumentTemplateService::TYPES)],
            'paper_type' => ['required', 'in:'.implode(',', CoreMarketDocumentTemplateService::PAPER_TYPES)],
            'width_mm' => ['nullable', 'numeric', 'min:10', 'max:500'],
            'height_mm' => ['nullable', 'numeric', 'min:10', 'max:1000'],
            'margin_top_mm' => ['nullable', 'numeric', 'min:0', 'max:50'],
            'margin_right_mm' => ['nullable', 'numeric', 'min:0', 'max:50'],
            'margin_bottom_mm' => ['nullable', 'numeric', 'min:0', 'max:50'],
            'margin_left_mm' => ['nullable', 'numeric', 'min:0', 'max:50'],
            'primary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'font_size' => ['required', 'numeric', 'min:7', 'max:24'],
            'logo_position' => ['required', 'in:left,center,right'],
            'footer_text' => ['nullable', 'string', 'max:500'],
            'columns' => ['nullable', 'array'],
            'columns.*' => ['string'],
        ]);

        try {
            $settings = $templates->validateSettings([
                'logo_enabled' => $request->boolean('logo_enabled'),
                'logo_position' => $data['logo_position'],
                'primary_color' => $data['primary_color'],
                'secondary_color' => $data['secondary_color'],
                'font_size' => $data['font_size'],
                'show_store_name' => $request->boolean('show_store_name'),
                'show_supplier_info' => $request->boolean('show_supplier_info'),
                'show_customer_info' => $request->boolean('show_customer_info'),
                'show_barcode' => $request->boolean('show_barcode'),
                'show_sku' => $request->boolean('show_sku'),
                'show_tax' => $request->boolean('show_tax'),
                'show_discount' => $request->boolean('show_discount'),
                'show_family' => $request->boolean('show_family'),
                'show_footer' => $request->boolean('show_footer'),
                'footer_text' => $data['footer_text'] ?? '',
                'columns' => $data['columns'] ?? [],
            ]);
        } catch (DomainException $exception) {
            abort(422, $exception->getMessage());
        }

        return collect($data)->except(['primary_color', 'secondary_color', 'font_size', 'logo_position', 'footer_text', 'columns'])->merge([
            'is_default' => $request->boolean('is_default'),
            'is_active' => $request->boolean('is_active'),
            'settings' => $settings,
        ])->all();
    }

    private function authorizePermission(string $permission): void
    {
        $user = auth()->user();
        abort_unless($user && ($user->user_type === 'admin' || $user->can($permission)), 403);
    }
}
