<?php

namespace App\Http\Controllers;

use App\Models\AccountingEvent;
use App\Models\AccountingAccount;
use App\Models\BusinessSetting;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CashierShift;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductStock;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReturn;
use App\Models\SalesReturn;
use App\Models\Supplier;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\AccountingPostingService;
use App\Services\AccountingReportService;
use App\Services\AccountingEventService;
use App\Services\AccountingSummaryService;
use App\Services\CoreMarketFeatureAccessService;
use App\Services\CoreMarketAccountingReportService;
use App\Services\CoreMarketInventoryPolicyService;
use App\Services\CoreMarketBranchService;
use App\Services\CoreMarketBranchInventoryService;
use App\Services\CoreMarketPricingFeatureService;
use App\Services\CoreMarketProductClassificationService;
use App\Services\CoreMarketProductQuickCreateService;
use App\Services\CoreMarketSalesReturnRefundService;
use App\Services\CoreMarketTaxService;
use App\Services\CoreMarketDocumentTemplateService;
use App\Services\InventoryProService;
use App\Services\CoreMarketInventoryAdjustmentService;
use App\Services\OperationsPdfService;
use App\Services\ProductIdentityLookupService;
use App\Services\PurchaseItemPricingService;
use App\Services\PurchaseReceivingService;
use App\Services\QuickProductValidationException;
use App\Services\PurchaseReturnService;
use App\Services\PurchasingUiService;
use App\Services\SalesReturnService;
use App\Services\SalesReturnUiService;
use App\Services\SupplierLedgerService;
use App\Services\SupplierPaymentService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use PDF;

class OperationsController extends Controller
{
    public function __construct(private CoreMarketFeatureAccessService $features)
    {
    }

    public function overview(): View
    {
        $this->authorizeOperation('operations.view', ['inventory_pro', 'purchasing_suppliers', 'returns_management', 'accounting_lite']);

        return view('backend.operations.overview', [
            'quickActions' => $this->operationsQuickActions(),
            'movementCount' => InventoryMovement::count(),
            'recentProductCount' => InventoryMovement::query()->where('created_at', '>=', now()->subDays(30))->distinct('product_id')->count('product_id'),
            'openPurchaseOrders' => PurchaseOrder::query()->whereIn('status', ['draft', 'ordered', 'partially_received'])->count(),
            'activeSalesReturns' => SalesReturn::query()->whereIn('status', ['draft', 'requested', 'approved'])->count(),
            'monthExpenses' => Expense::query()->whereIn('status', ['approved', 'paid'])->whereBetween('expense_date', [now()->startOfMonth(), now()->endOfMonth()])->sum('amount'),
            'summary' => app(AccountingSummaryService::class)->summary(),
        ]);
    }

    public function inventoryMovements(
        Request $request,
        CoreMarketBranchInventoryService $branchInventory
    ): View
    {
        $this->authorizeOperation('inventory_movements.view', ['inventory_pro', 'accounting_lite']);
        $query = InventoryMovement::query()->with(['product.productFamily', 'product.productSubFamily', 'productStock', 'order'])->latest();
        foreach (['movement_type', 'direction', 'product_id'] as $filter) {
            if ($request->filled($filter)) $query->where($filter, $request->input($filter));
        }
        if ($request->filled('product_family_id')) {
            $query->whereHas('product', fn ($product) => $product->where('product_family_id', $request->integer('product_family_id')));
        }
        if ($request->filled('product_sub_family_id')) {
            $query->whereHas('product', fn ($product) => $product->where('product_sub_family_id', $request->integer('product_sub_family_id')));
        }
        if ($request->filled('from')) $query->whereDate('created_at', '>=', $request->input('from'));
        if ($request->filled('to')) $query->whereDate('created_at', '<=', $request->input('to'));
        if ($request->filled('branch_id')) {
            $branch = $branchInventory->resolveBranchForOperation($request->integer('branch_id'), auth()->user());
            $query->where('metadata->store_branch_id', $branch->id);
        }
        $branches = $branchInventory->visibleBranches(auth()->user());

        return view('backend.operations.inventory-movements', [
            'movements' => $query->paginate(30)->withQueryString(),
            'products' => Product::query()->orderBy('name')->limit(250)->get(),
            'families' => ProductFamily::query()->families()->active()->with(['children' => fn ($children) => $children->active()])->orderBy('name')->get(),
            'branches' => $branches,
            'branchMap' => $branches->keyBy('id'),
        ]);
    }

    public function inventoryDashboard(InventoryProService $inventory): View
    {
        $this->authorizeOperation('inventory.dashboard.view', ['inventory_pro']);
        return view('backend.operations.inventory.dashboard', ['stats' => $inventory->dashboardStats()]);
    }

    public function inventoryStock(Request $request, InventoryProService $inventory): View
    {
        $this->authorizeOperation('inventory.stock.view', ['inventory_pro']);
        return view('backend.operations.inventory.stock', [
            'rows' => $inventory->stockRows($request->only(['search', 'status', 'low_stock_only', 'product_family_id', 'product_sub_family_id'])),
            'families' => ProductFamily::query()->families()->active()->with(['children' => fn ($children) => $children->active()])->orderBy('name')->get(),
        ]);
    }

    public function barcodeLookup(Request $request, ProductIdentityLookupService $lookup, InventoryProService $inventory): View
    {
        $this->authorizeOperation('inventory.barcode_lookup.view', ['inventory_pro']);
        $identity = trim((string) $request->input('barcode_or_sku'));
        $result = $identity === '' ? null : $lookup->find($identity);
        $lastMovement = $result ? InventoryMovement::query()->where('product_stock_id', $result['product_stock']?->id)->orWhere(fn ($q) => $q->whereNull('product_stock_id')->where('product_id', $result['product']->id))->latest()->first() : null;
        return view('backend.operations.inventory.barcode-lookup', compact('identity', 'result', 'lastMovement'));
    }

    public function lowStock(Request $request, InventoryProService $inventory): View
    {
        $this->authorizeOperation('inventory.low_stock.view', ['inventory_pro']);
        return view('backend.operations.inventory.low-stock', ['rows' => $inventory->lowStockRows($request->only(['search', 'status']))]);
    }

    public function inventoryAudit(InventoryProService $inventory): View
    {
        $this->authorizeOperation('inventory.stock.audit', ['inventory_pro']);
        return view('backend.operations.inventory.audit', ['audit' => $inventory->auditSummary()]);
    }

    public function inventoryPolicy(
        CoreMarketInventoryPolicyService $policy,
        CoreMarketBranchInventoryService $branchInventory
    ): View
    {
        $this->authorizeOperation('inventory.stock.adjust', ['inventory_pro']);

        return view('backend.operations.inventory.policy', [
            'policy' => array_merge($policy->policySnapshot(), [
                'branch_inventory_enabled' => $branchInventory->branchInventoryEnabled(),
                'serial_tracking_enabled' => filter_var(get_setting('inventory.serial_tracking_enabled', config('coremarket.inventory.serial_tracking_enabled', false)), FILTER_VALIDATE_BOOL),
                'imei_tracking_enabled' => filter_var(get_setting('inventory.imei_tracking_enabled', config('coremarket.inventory.imei_tracking_enabled', false)), FILTER_VALIDATE_BOOL),
                'warranty_tracking_enabled' => filter_var(get_setting('inventory.warranty_tracking_enabled', config('coremarket.inventory.warranty_tracking_enabled', false)), FILTER_VALIDATE_BOOL),
                'advanced_variants_enabled' => filter_var(get_setting('catalog.advanced_variants_enabled', config('coremarket.catalog.advanced_variants_enabled', false)), FILTER_VALIDATE_BOOL),
            ]),
        ]);
    }

    public function updateInventoryPolicy(Request $request): RedirectResponse
    {
        $this->authorizeOperation('inventory.stock.adjust', ['inventory_pro']);
        $data = $request->validate([
            'strict_inventory_mode' => 'required|boolean',
            'allow_negative_stock' => 'required|boolean',
            'setup_mode_enabled' => 'required|boolean',
            'opening_stock_enabled' => 'required|boolean',
            'adjustments_enabled' => 'required|boolean',
            'adjustment_requires_approval' => 'required|boolean',
            'stock_counts_enabled' => 'required|boolean',
            'emergency_adjustment_enabled' => 'required|boolean',
            'branch_inventory_enabled' => 'required|boolean',
            'serial_tracking_enabled' => 'required|boolean',
            'imei_tracking_enabled' => 'required|boolean',
            'warranty_tracking_enabled' => 'required|boolean',
            'advanced_variants_enabled' => 'required|boolean',
        ]);

        foreach ([
            CoreMarketInventoryPolicyService::STRICT_MODE_SETTING => $data['strict_inventory_mode'],
            CoreMarketInventoryPolicyService::NEGATIVE_STOCK_SETTING => $data['allow_negative_stock'],
            CoreMarketInventoryPolicyService::SETUP_MODE_SETTING => $data['setup_mode_enabled'],
            CoreMarketInventoryPolicyService::OPENING_STOCK_SETTING => $data['opening_stock_enabled'],
            CoreMarketInventoryPolicyService::ADJUSTMENTS_SETTING => $data['adjustments_enabled'],
            CoreMarketInventoryPolicyService::ADJUSTMENT_APPROVAL_SETTING => $data['adjustment_requires_approval'],
            CoreMarketInventoryPolicyService::STOCK_COUNTS_SETTING => $data['stock_counts_enabled'],
            CoreMarketInventoryPolicyService::EMERGENCY_ADJUSTMENT_SETTING => $data['emergency_adjustment_enabled'],
            CoreMarketBranchInventoryService::SETTING => $data['branch_inventory_enabled'],
            'inventory.serial_tracking_enabled' => $data['serial_tracking_enabled'],
            'inventory.imei_tracking_enabled' => $data['imei_tracking_enabled'],
            'inventory.warranty_tracking_enabled' => $data['warranty_tracking_enabled'],
            'catalog.advanced_variants_enabled' => $data['advanced_variants_enabled'],
        ] as $type => $value) {
            $setting = BusinessSetting::query()->where('type', $type)->whereNull('lang')->first() ?: new BusinessSetting();
            $setting->forceFill(['type' => $type, 'value' => $value ? '1' : '0', 'lang' => null])->save();
        }
        Cache::forget('business_settings');

        return back()->with('success', translate('Inventory policy updated successfully'));
    }

    public function adjustStockForm(ProductStock $productStock): RedirectResponse
    {
        $this->authorizeOperation('inventory.adjustments.create', ['inventory_pro']);

        return redirect()->route('operations.inventory.adjustments.create', [
            'product_stock_id' => $productStock->id,
        ]);
    }

    public function adjustStock(
        Request $request,
        ProductStock $productStock,
        CoreMarketInventoryAdjustmentService $adjustments
    ): RedirectResponse
    {
        $this->authorizeOperation('inventory.adjustments.create', ['inventory_pro']);
        $data = $request->validate(['adjustment_type' => 'required|in:increase,decrease,set', 'quantity' => 'required|numeric|min:0', 'reason' => 'required|string|max:255', 'notes' => 'nullable|string|max:2000']);
        try {
            $current = (float) $productStock->qty;
            $change = match ($data['adjustment_type']) {
                'increase' => (float) $data['quantity'],
                'decrease' => -(float) $data['quantity'],
                'set' => (float) $data['quantity'] - $current,
            };
            $document = $adjustments->createAdjustmentDocument([
                'adjustment_type' => 'stock_adjustment',
                'reason' => $data['reason'],
                'notes' => $data['notes'] ?? null,
                'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
                'items' => [[
                    'product_stock_id' => $productStock->id,
                    'quantity_change' => $change,
                ]],
            ], auth()->user());
            $adjustments->submitForApproval($document, auth()->user());
        } catch (DomainException $exception) {
            return back()->withErrors(['quantity' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('operations.inventory.adjustments.show', $document)
            ->with('success', translate('Stock adjustment document created. Stock has not changed.'));
    }

    public function suppliers(Request $request, PurchasingUiService $purchasing): View
    {
        $this->authorizeOperation('suppliers.view', ['purchasing_suppliers']);
        return view('backend.operations.suppliers.index', ['suppliers' => $purchasing->suppliers($request->only(['search', 'status']))]);
    }
    public function createSupplier(): View { $this->authorizeOperation('suppliers.create', ['purchasing_suppliers']); return view('backend.operations.suppliers.form', ['supplier' => new Supplier()]); }
    public function showSupplier(Supplier $supplier, SupplierLedgerService $ledger): View
    {
        $this->authorizeOperation('supplier_ledger.view', ['purchasing_suppliers']);

        return view('backend.operations.suppliers.show', [
            'supplier' => $supplier,
            'balance' => $ledger->supplierBalance($supplier),
            'ledgerEntries' => $supplier->ledgerEntries()->latest('occurred_at')->paginate(25),
            'payments' => $supplier->payments()->with('purchaseOrder')->latest('paid_at')->limit(10)->get(),
            'purchaseReturns' => $supplier->purchaseReturns()->with('purchaseOrder')->latest()->limit(10)->get(),
            'purchaseOrders' => $supplier->purchaseOrders()->latest()->limit(100)->get(),
            'paymentKey' => (string) Str::uuid(),
        ]);
    }
    public function supplierStatementPdf(Request $request, Supplier $supplier, OperationsPdfService $pdf, CoreMarketDocumentTemplateService $templates)
    {
        $this->authorizeOperation('supplier_ledger.view', ['purchasing_suppliers']);
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
        $data = $pdf->supplierStatement(
            $supplier,
            $filters['date_from'] ?? null,
            $filters['date_to'] ?? null
        );

        $contents = PDF::loadView('backend.operations.pdf.supplier-statement', $data, [], $templates->paperConfig($data['template']))->output();

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="supplier-statement-'.$supplier->id.'.pdf"',
        ]);
    }
    public function salesInvoicePdf(Order $order, OperationsPdfService $pdf, CoreMarketDocumentTemplateService $templates)
    {
        $this->authorizeSalesInvoice($order);
        $data = $pdf->salesInvoice($order);
        $contents = PDF::loadView('backend.operations.pdf.sales-invoice', $data, [], $templates->paperConfig($data['template']))->output();

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="sales-invoice-'.$order->code.'.pdf"',
        ]);
    }
    public function customerStatementPdf(Request $request, User $customer, OperationsPdfService $pdf, CoreMarketDocumentTemplateService $templates)
    {
        $this->authorizeDocumentPermission('customer_statements.export');
        abort_unless($customer->user_type === 'customer', 404);
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
        $data = $pdf->customerStatement($customer, $filters['date_from'] ?? null, $filters['date_to'] ?? null);
        $contents = PDF::loadView('backend.operations.pdf.customer-statement', $data, [], $templates->paperConfig($data['template']))->output();

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="customer-statement-'.$customer->id.'.pdf"',
        ]);
    }
    public function deliveryNotePdf(Order $order, OperationsPdfService $pdf, CoreMarketDocumentTemplateService $templates)
    {
        return $this->deliveryDocumentResponse($order, 'delivery_note', $pdf, $templates);
    }
    public function packingSlipPdf(Order $order, OperationsPdfService $pdf, CoreMarketDocumentTemplateService $templates)
    {
        return $this->deliveryDocumentResponse($order, 'packing_slip', $pdf, $templates);
    }
    public function editSupplier(Supplier $supplier): View { $this->authorizeOperation('suppliers.edit', ['purchasing_suppliers']); return view('backend.operations.suppliers.form', compact('supplier')); }
    public function storeSupplier(Request $request): RedirectResponse { $this->authorizeOperation('suppliers.create', ['purchasing_suppliers']); $supplier = Supplier::create($this->supplierData($request)); return redirect()->route('operations.suppliers.edit', $supplier)->with('success', translate('Supplier saved successfully')); }
    public function updateSupplier(Request $request, Supplier $supplier): RedirectResponse { $this->authorizeOperation('suppliers.edit', ['purchasing_suppliers']); $supplier->update($this->supplierData($request)); return back()->with('success', translate('Supplier saved successfully')); }
    public function storeSupplierPayment(Request $request, Supplier $supplier, SupplierPaymentService $payments): RedirectResponse
    {
        $this->authorizeOperation('supplier_payments.create', ['purchasing_suppliers']);
        $data = $request->validate([
            'payment_key' => 'required|string|max:100',
            'purchase_order_id' => 'nullable|exists:purchase_orders,id',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|string|max:10',
            'exchange_rate' => 'required|numeric|min:0.000001',
            'payment_method' => 'nullable|in:cash,bank_transfer,card,cheque,other',
            'payment_reference' => 'nullable|string|max:255',
            'paid_at' => 'required|date',
            'notes' => 'nullable|string|max:2000',
        ]);
        try {
            $payments->createPayment($supplier, $data, auth()->id());
        } catch (DomainException|\InvalidArgumentException $exception) {
            return back()->withErrors(['payment' => $exception->getMessage()])->withInput();
        }

        return back()->with('success', translate('Supplier payment recorded successfully'));
    }

    public function purchaseOrders(Request $request, PurchasingUiService $purchasing): View
    {
        $this->authorizeOperation('purchase_orders.view', ['purchasing_suppliers']);
        return view('backend.operations.purchase-orders.index', [
            'purchaseOrders' => $purchasing->purchaseOrders($request->only(['supplier_id', 'status', 'from', 'to'])),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
    public function createPurchaseOrder(
        CoreMarketTaxService $tax,
        CoreMarketInventoryPolicyService $inventoryPolicy,
        CoreMarketProductClassificationService $classification,
        CoreMarketPricingFeatureService $pricingFeatures,
        CoreMarketBranchService $branches
    ): View
    {
        $this->authorizeOperation('purchase_orders.create', ['purchasing_suppliers']);
        return view('backend.operations.purchase-orders.form', [
            'suppliers' => Supplier::query()->where('is_active', true)->orderBy('name')->get(),
            'products' => Product::query()->orderBy('name')->limit(500)->get(),
            'productStocks' => ProductStock::query()->with('product')->orderBy('product_id')->get(),
            'defaultTaxRate' => $tax->getDefaultTaxRate(),
            'quickProductAllowed' => auth()->user()?->user_type === 'admin' || (bool) auth()->user()?->can('add_new_product'),
            'quickProductFamilies' => $classification->families(),
            'quickProductBrands' => Brand::query()->orderBy('name')->get(['id', 'name']),
            'quickProductCategories' => Category::query()->where('digital', 0)->orderBy('name')->get(['id', 'name']),
            'strictInventoryMode' => $inventoryPolicy->strictInventoryMode(),
            'priceListsEnabled' => $pricingFeatures->priceListsEnabled(),
            'purchaseBranch' => $branches->branchesEnabled() ? $branches->defaultBranch() : null,
        ]);
    }
    public function purchaseOrderProductLookup(
        Request $request,
        ProductIdentityLookupService $lookup,
        PurchaseItemPricingService $pricing
    ): JsonResponse {
        $this->authorizeOperation('purchase_orders.create', ['purchasing_suppliers']);
        $data = $request->validate(['q' => 'required|string|max:100']);
        $result = $lookup->find($data['q']);
        if (! $result) {
            return response()->json([
                'ok' => false,
                'message' => 'Product not found. Create product first or use manual item entry.',
                'reason' => 'not_found',
                'query' => $data['q'],
                'suggested_actions' => ['correct_search', 'add_product'],
            ], 404);
        }

        $product = $result['product'];
        $stock = $result['product_stock'];
        $regularPrice = is_numeric($stock?->price) ? (float) $stock->price : (float) $product->unit_price;
        $itemPricing = $pricing->calculate([
            'quantity_ordered' => 1,
            'unit_cost' => $product->purchase_price,
            'regular_price' => $regularPrice,
            'tax_enabled' => false,
        ], $regularPrice);

        return response()->json([
            'ok' => true,
            'data' => [
                'product_id' => $product->id,
                'product_stock_id' => $stock?->id,
                'name' => $product->name,
                'variant' => $result['variant'],
                'sku' => $stock?->sku,
                'barcode' => $stock?->barcode ?: $product->barcode,
                'cost_price' => $itemPricing['cost_price'],
                'regular_price' => $itemPricing['regular_price'],
                'sale_price' => null,
                'margin_percent' => $itemPricing['margin_percent'],
                'matched_by' => $result['matched_by'],
            ],
        ]);
    }

    public function quickCreatePurchaseProduct(
        Request $request,
        CoreMarketProductQuickCreateService $quickCreate
    ): JsonResponse {
        $this->authorizeOperation('purchase_orders.create', ['purchasing_suppliers']);
        $user = auth()->user();
        if (! $user || ($user->user_type !== 'admin' && ! $user->can('add_new_product'))) {
            abort(403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:50'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'product_family_id' => ['nullable', 'integer', 'exists:product_families,id'],
            'product_sub_family_id' => ['nullable', 'integer', 'exists:product_families,id'],
            'cost_price' => ['required', 'numeric', 'min:0'],
            'regular_price' => ['nullable', 'numeric', 'gt:0', 'required_without:margin_percent'],
            'margin_percent' => ['nullable', 'numeric', 'required_without:regular_price'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'tax_enabled' => ['nullable', 'boolean'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'opening_stock' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            return response()->json([
                'ok' => true,
                'message' => 'Product created and added to the purchase order.',
                'data' => $quickCreate->create($data, $user),
            ], 201);
        } catch (QuickProductValidationException $exception) {
            return response()->json([
                'ok' => false,
                'message' => 'Please review the product details.',
                'errors' => $exception->errors,
            ], 422);
        } catch (DomainException|\InvalidArgumentException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }
    }
    public function storePurchaseOrder(Request $request, PurchaseReceivingService $service): RedirectResponse
    {
        $this->authorizeOperation('purchase_orders.create', ['purchasing_suppliers']);
        $data = $request->validate(['supplier_id' => 'nullable|exists:suppliers,id', 'ordered_at' => 'nullable|date', 'currency' => 'nullable|string|max:10', 'notes' => 'nullable|string|max:2000', 'items' => 'required|array|min:1', 'items.*.product_id' => 'required|exists:products,id', 'items.*.product_stock_id' => 'nullable|exists:product_stocks,id', 'items.*.variant' => 'nullable|string|max:255', 'items.*.quantity_ordered' => 'required|numeric|min:0.000001', 'items.*.unit_cost' => 'nullable|numeric|min:0', 'items.*.regular_price' => 'nullable|numeric|min:0', 'items.*.sale_price' => 'nullable|numeric|min:0', 'items.*.margin_percent' => 'nullable|numeric', 'items.*.tax_enabled' => 'nullable|boolean', 'items.*.tax_rate' => 'nullable|numeric|min:0|max:100', 'items.*.tax_amount' => 'nullable|numeric|min:0', 'items.*.discount_amount' => 'nullable|numeric|min:0', 'items.*.notes' => 'nullable|string|max:1000']);
        try {
            $order = $service->createPurchaseOrder($data, $data['items'], auth()->id());
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['items' => $exception->getMessage()])->withInput();
        }
        return redirect()->route('operations.purchase-orders.show', $order)->with('success', translate('Purchase order created successfully'));
    }
    public function showPurchaseOrder(
        PurchaseOrder $purchaseOrder,
        PurchasingUiService $purchasing,
        CoreMarketBranchInventoryService $branchInventory
    ): View
    {
        $this->authorizeOperation('purchase_orders.view', ['purchasing_suppliers']);
        $purchaseOrder->load(['supplier', 'items.product', 'items.productStock', 'receipts.items.purchaseOrderItem']);
        $movementIds = $purchaseOrder->receipts->flatMap->items->pluck('inventory_movement_id')->filter();

        return view('backend.operations.purchase-orders.show', [
            'purchaseOrder' => $purchaseOrder,
            'progress' => $purchasing->progress($purchaseOrder),
            'movements' => InventoryMovement::query()->with(['product', 'productStock'])->whereIn('id', $movementIds)->latest()->get(),
            'branchInventoryEnabled' => $branchInventory->branchInventoryEnabled(),
            'branches' => $branchInventory->visibleBranches(auth()->user()),
        ]);
    }
    public function purchaseOrderPdf(PurchaseOrder $purchaseOrder, OperationsPdfService $pdf, CoreMarketDocumentTemplateService $templates)
    {
        $this->authorizeOperation('purchase_orders.view', ['purchasing_suppliers']);
        $data = $pdf->purchaseDocument($purchaseOrder);

        $contents = PDF::loadView('backend.operations.pdf.purchase-document', $data, [], $templates->paperConfig($data['template']))->output();

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="purchase-'.$purchaseOrder->purchase_number.'.pdf"',
        ]);
    }
    public function receivePurchaseOrder(Request $request, PurchaseOrder $purchaseOrder, PurchaseReceivingService $service): RedirectResponse
    {
        $this->authorizeOperation('purchase_orders.receive', ['purchasing_suppliers']);
        $data = $request->validate(['receipt_key' => 'required|string|max:100', 'branch_id' => 'nullable|integer|exists:store_branches,id', 'notes' => 'nullable|string|max:2000', 'items' => 'required|array|min:1', 'items.*.purchase_order_item_id' => 'required|exists:purchase_order_items,id', 'items.*.quantity_received' => 'required|numeric|min:0', 'items.*.unit_cost' => 'nullable|numeric|min:0', 'items.*.serial_entries' => 'nullable|string|max:20000']);
        $items = collect($data['items'])->filter(fn ($item) => (float) $item['quantity_received'] > 0)->values()->all();
        foreach ($items as &$item) {
            $item['serials'] = collect(preg_split('/\R/', (string) ($item['serial_entries'] ?? '')))
                ->map(function ($line) {
                    $parts = array_map('trim', explode('|', $line));
                    return [
                        'serial_number' => $parts[0] ?? null,
                        'imei_1' => $parts[1] ?? null,
                        'imei_2' => $parts[2] ?? null,
                    ];
                })
                ->filter(fn ($identity) => collect($identity)->filter()->isNotEmpty())
                ->values()
                ->all();
            unset($item['serial_entries']);
        }
        unset($item);
        if (empty($items)) return back()->withErrors(['items' => translate('Enter a quantity to receive.')]);
        try {
            $service->receive($purchaseOrder, $items, $data, auth()->id());
        } catch (DomainException $exception) {
            return back()->withErrors(['items' => $exception->getMessage()])->withInput();
        }
        return back()->with('success', translate('Stock received successfully'));
    }

    public function purchaseReceipts(Request $request, PurchasingUiService $purchasing): View
    {
        $this->authorizeOperation('purchase_orders.view', ['purchasing_suppliers']);
        return view('backend.operations.purchase-receipts.index', [
            'receipts' => $purchasing->receipts($request->only(['supplier_id', 'from', 'to'])),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function showPurchaseReceipt(PurchaseReceipt $purchaseReceipt): View
    {
        $this->authorizeOperation('purchase_orders.view', ['purchasing_suppliers']);
        $purchaseReceipt->load(['purchaseOrder.supplier', 'items.purchaseOrderItem.product', 'items.purchaseOrderItem.productStock']);
        $movements = InventoryMovement::query()->with(['product', 'productStock'])
            ->whereIn('id', $purchaseReceipt->items->pluck('inventory_movement_id')->filter())
            ->latest()->get();

        return view('backend.operations.purchase-receipts.show', compact('purchaseReceipt', 'movements'));
    }
    public function purchaseReceiptPdf(PurchaseReceipt $purchaseReceipt, OperationsPdfService $pdf, CoreMarketDocumentTemplateService $templates)
    {
        $this->authorizeOperation('purchase_orders.view', ['purchasing_suppliers']);
        $data = $pdf->purchaseDocument($purchaseReceipt->purchaseOrder, $purchaseReceipt);

        $contents = PDF::loadView('backend.operations.pdf.purchase-document', $data, [], $templates->paperConfig($data['template']))->output();

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="purchase-receipt-'.$purchaseReceipt->id.'.pdf"',
        ]);
    }

    public function purchaseReturns(Request $request): View
    {
        $this->authorizeOperation('purchase_returns.view', ['purchasing_suppliers']);
        $returns = PurchaseReturn::query()
            ->with(['supplier', 'purchaseOrder'])
            ->when($request->input('supplier_id'), fn ($query, $supplierId) => $query->where('supplier_id', $supplierId))
            ->when($request->input('status'), fn ($query, $status) => $query->where('status', $status))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('backend.operations.purchase-returns.index', [
            'purchaseReturns' => $returns,
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function createPurchaseReturn(
        Request $request,
        CoreMarketBranchInventoryService $branchInventory
    ): View
    {
        $this->authorizeOperation('purchase_returns.create', ['purchasing_suppliers']);
        $purchaseOrder = $request->filled('purchase_order_id')
            ? PurchaseOrder::query()->with(['supplier', 'items.product', 'items.productStock', 'items.purchaseReturnItems.purchaseReturn'])->findOrFail($request->integer('purchase_order_id'))
            : null;

        return view('backend.operations.purchase-returns.form', [
            'purchaseOrder' => $purchaseOrder,
            'purchaseOrders' => PurchaseOrder::query()
                ->with('supplier')
                ->whereNotNull('supplier_id')
                ->whereIn('status', ['partially_received', 'received'])
                ->latest()
                ->limit(200)
                ->get(),
            'branchInventoryEnabled' => $branchInventory->branchInventoryEnabled(),
            'branches' => $branchInventory->visibleBranches(auth()->user()),
        ]);
    }

    public function storePurchaseReturn(Request $request, PurchaseReturnService $service): RedirectResponse
    {
        $this->authorizeOperation('purchase_returns.create', ['purchasing_suppliers']);
        $data = $request->validate([
            'purchase_order_id' => 'required|exists:purchase_orders,id',
            'branch_id' => 'nullable|integer|exists:store_branches,id',
            'return_date' => 'required|date',
            'reason' => 'nullable|string|max:2000',
            'notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1',
            'items.*.purchase_order_item_id' => 'required|exists:purchase_order_items,id',
            'items.*.quantity' => 'required|numeric|min:0',
        ]);
        $items = collect($data['items'])->filter(fn ($item) => (float) $item['quantity'] > 0)->values()->all();
        if ($items === []) {
            return back()->withErrors(['items' => translate('Enter a quantity to return.')])->withInput();
        }
        try {
            $return = $service->createDraft(PurchaseOrder::findOrFail($data['purchase_order_id']), $items, $data, auth()->id());
        } catch (DomainException|\InvalidArgumentException $exception) {
            return back()->withErrors(['items' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('operations.purchase-returns.show', $return)->with('success', translate('Purchase return draft created successfully'));
    }

    public function showPurchaseReturn(PurchaseReturn $purchaseReturn): View
    {
        $this->authorizeOperation('purchase_returns.view', ['purchasing_suppliers']);
        $purchaseReturn->load(['supplier', 'purchaseOrder', 'items.product', 'items.productStock', 'items.purchaseOrderItem']);

        return view('backend.operations.purchase-returns.show', compact('purchaseReturn'));
    }

    public function completePurchaseReturn(PurchaseReturn $purchaseReturn, PurchaseReturnService $service): RedirectResponse
    {
        $this->authorizeOperation('purchase_returns.complete', ['purchasing_suppliers']);
        try {
            $service->complete($purchaseReturn, auth()->id());
        } catch (DomainException $exception) {
            return back()->withErrors(['purchase_return' => $exception->getMessage()]);
        }

        return back()->with('success', translate('Purchase return completed successfully'));
    }

    public function cancelPurchaseReturn(PurchaseReturn $purchaseReturn, PurchaseReturnService $service): RedirectResponse
    {
        $this->authorizeOperation('purchase_returns.cancel', ['purchasing_suppliers']);
        try {
            $service->cancel($purchaseReturn);
        } catch (DomainException $exception) {
            return back()->withErrors(['purchase_return' => $exception->getMessage()]);
        }

        return back()->with('success', translate('Purchase return cancelled successfully'));
    }

    public function salesReturns(Request $request, SalesReturnUiService $returns): View
    {
        $this->authorizeOperation('sales_returns.view', ['returns_management']);
        return view('backend.operations.sales-returns.index', [
            'salesReturns' => $returns->returns($request->only(['status', 'return_type', 'order_id', 'completed', 'from', 'to'])),
            'orders' => Order::query()->latest()->limit(100)->get(['id', 'code']),
        ]);
    }

    public function createSalesReturn(Request $request, SalesReturnUiService $returns): View
    {
        $this->authorizeOperation('sales_returns.create', ['returns_management']);
        $order = $request->filled('order_id') ? Order::findOrFail($request->integer('order_id')) : null;
        return view('backend.operations.sales-returns.form', [
            'orders' => Order::query()->latest()->limit(100)->get(['id', 'code']),
            'order' => $order,
            'returnableRows' => $order ? $returns->orderReturnableRows($order) : [],
        ]);
    }
    public function storeSalesReturn(Request $request, SalesReturnService $service): RedirectResponse
    {
        $this->authorizeOperation('sales_returns.create', ['returns_management']);
        $data = $request->validate(['order_id' => 'required|exists:orders,id', 'reason' => 'nullable|string|max:1000', 'notes' => 'nullable|string|max:2000', 'items' => 'required|array|min:1', 'items.*.order_detail_id' => 'required|exists:order_details,id', 'items.*.quantity' => 'required|numeric|min:0', 'items.*.reason' => 'nullable|string|max:1000', 'items.*.serial_unit_ids' => 'nullable|array', 'items.*.serial_unit_ids.*' => 'integer|exists:product_serial_units,id']);
        $items = collect($data['items'])->filter(fn ($item) => (float) $item['quantity'] > 0)->values()->all();
        if (empty($items)) return back()->withErrors(['items' => translate('Enter a quantity to return.')]);
        try {
            $return = $service->create(Order::findOrFail($data['order_id']), $items, $data, auth()->id());
        } catch (DomainException $exception) {
            return back()->withErrors(['items' => $exception->getMessage()])->withInput();
        }
        return redirect()->route('operations.sales-returns.show', $return)->with('success', translate('Sales return created successfully'));
    }
    public function showSalesReturn(
        SalesReturn $salesReturn,
        SalesReturnUiService $returns,
        CoreMarketSalesReturnRefundService $refunds
    ): View
    {
        $this->authorizeOperation('sales_returns.view', ['returns_management']);
        $salesReturn->load(['order.user', 'items.product', 'items.productStock', 'items.orderDetail']);
        $user = auth()->user();
        $canViewRefunds = $user->user_type === 'admin' || $user->can('sales_returns.refunds.view');
        if ($canViewRefunds) {
            $salesReturn->load(['refunds.refundedBy', 'refunds.cashMovement', 'refunds.customerLedgerEntry']);
        }
        return view('backend.operations.sales-returns.show', [
            'salesReturn' => $salesReturn,
            'movements' => $returns->linkedMovements($salesReturn),
            'accountingEvents' => $returns->accountingEvents($salesReturn),
            'canViewRefunds' => $canViewRefunds,
            'canViewReturnFinancials' => $user->user_type === 'admin' || $user->can('accounting_summary.view'),
            'canCashRefund' => $user->user_type === 'admin' || $user->can('sales_returns.refunds.cash'),
            'canCreditAccount' => $user->user_type === 'admin' || $user->can('sales_returns.refunds.credit_account'),
            'refundSnapshot' => $canViewRefunds ? $refunds->refundSnapshot($salesReturn) : null,
            'openCashierShifts' => ($user->user_type === 'admin' || $user->can('sales_returns.refunds.cash'))
                ? CashierShift::query()->with('cashbox')->where('status', 'open')->latest('opened_at')->get()
                : collect(),
            'cashRefundKey' => (string) Str::uuid(),
            'accountCreditKey' => (string) Str::uuid(),
        ]);
    }
    public function completeSalesReturn(SalesReturn $salesReturn, SalesReturnService $service): RedirectResponse
    {
        $this->authorizeOperation('sales_returns.complete', ['returns_management']);
        $alreadyCompleted = $salesReturn->status === 'completed';
        try {
            $service->complete($salesReturn, auth()->id());
        } catch (DomainException $exception) {
            return back()->withErrors(['sales_return' => $exception->getMessage()]);
        }
        return back()->with('success', $alreadyCompleted ? translate('Sales return was already completed.') : translate('Sales return completed successfully'));
    }

    public function refundSalesReturnCash(
        Request $request,
        SalesReturn $salesReturn,
        CoreMarketSalesReturnRefundService $refunds
    ): RedirectResponse {
        $this->authorizeOperation('sales_returns.refunds.cash', ['returns_management']);
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.000001',
            'cashier_shift_id' => 'required|exists:cashier_shifts,id',
            'notes' => 'nullable|string|max:2000',
            'idempotency_key' => 'required|string|max:120',
        ]);
        try {
            $refunds->refundToCash(
                $salesReturn,
                $data['amount'],
                auth()->user(),
                CashierShift::findOrFail($data['cashier_shift_id']),
                $data['idempotency_key'],
                $data['notes'] ?? null
            );
        } catch (DomainException $exception) {
            return back()->withErrors(['refund' => $exception->getMessage()])->withInput();
        }

        return back()->with('success', translate('Cash refund posted successfully'));
    }

    public function creditSalesReturnAccount(
        Request $request,
        SalesReturn $salesReturn,
        CoreMarketSalesReturnRefundService $refunds
    ): RedirectResponse {
        $this->authorizeOperation('sales_returns.refunds.credit_account', ['returns_management']);
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.000001',
            'notes' => 'nullable|string|max:2000',
            'idempotency_key' => 'required|string|max:120',
        ]);
        try {
            $refunds->creditCustomerAccount(
                $salesReturn,
                $data['amount'],
                auth()->user(),
                $data['idempotency_key'],
                $data['notes'] ?? null
            );
        } catch (DomainException $exception) {
            return back()->withErrors(['refund' => $exception->getMessage()])->withInput();
        }

        return back()->with('success', translate('Customer account credit posted successfully'));
    }

    public function expenses(): View { $this->authorizeOperation('expenses.view', ['accounting_lite']); return view('backend.operations.expenses.index', ['expenses' => Expense::with('category')->latest()->paginate(25)]); }
    public function createExpense(): View { $this->authorizeOperation('expenses.create', ['accounting_lite']); return view('backend.operations.expenses.form', ['categories' => ExpenseCategory::query()->where('is_active', true)->orderBy('name')->get()]); }
    public function storeExpense(Request $request): RedirectResponse { $this->authorizeOperation('expenses.create', ['accounting_lite']); $data = $request->validate(['expense_category_id' => 'nullable|exists:expense_categories,id', 'title' => 'required|string|max:255', 'amount' => 'required|numeric|min:0', 'currency' => 'nullable|string|max:10', 'expense_date' => 'nullable|date', 'payment_method' => 'nullable|string|max:100', 'vendor_name' => 'nullable|string|max:255', 'reference_number' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:2000']); $expense = Expense::create(array_merge($data, ['status' => 'draft', 'created_by' => auth()->id()])); return redirect()->route('operations.expenses.show', $expense)->with('success', translate('Expense created successfully')); }
    public function showExpense(Expense $expense): View { $this->authorizeOperation('expenses.view', ['accounting_lite']); return view('backend.operations.expenses.show', compact('expense')); }
    public function approveExpense(Expense $expense, AccountingEventService $service): RedirectResponse { $this->authorizeOperation('expenses.approve', ['accounting_lite']); $service->approveExpense($expense, auth()->id()); return back()->with('success', translate('Expense approved successfully')); }
    public function accountingSummary(): View { $this->authorizeOperation('accounting_summary.view', ['accounting_lite']); return view('backend.operations.accounting-summary', ['summary' => app(AccountingSummaryService::class)->summary()]); }
    public function accountingReports(Request $request, CoreMarketAccountingReportService $reports): View
    {
        $this->authorizeOperation('accounting_summary.view', ['accounting_lite', 'accounting_core']);
        $filters = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
        ]);

        return view('backend.operations.accounting.reports', $reports->report($filters));
    }
    public function accountingDashboard(AccountingReportService $reports): View
    {
        $this->authorizeOperation('accounting.core.view', ['accounting_core', 'accounting_lite']);
        return view('backend.operations.accounting.dashboard', ['stats' => $reports->dashboardStats()]);
    }
    public function accountingAccounts(): View
    {
        $this->authorizeOperation('accounting.accounts.view', ['accounting_core']);
        return view('backend.operations.accounting.accounts', ['accounts' => AccountingAccount::query()->orderBy('code')->paginate(50)]);
    }
    public function showAccountingAccount(AccountingAccount $account): View
    {
        $this->authorizeOperation('accounting.accounts.view', ['accounting_core']);
        $lines = $account->hasMany(\App\Models\JournalEntryLine::class, 'accounting_account_id')->with('journalEntry')->latest()->paginate(50);
        return view('backend.operations.accounting.account', compact('account', 'lines'));
    }
    public function journals(Request $request, AccountingReportService $reports): View
    {
        $this->authorizeOperation('accounting.journals.view', ['accounting_core']);
        return view('backend.operations.accounting.journals', ['journals' => $reports->journalRows($request->only(['status', 'source_type', 'account_id', 'from', 'to', 'unbalanced']))->paginate(30)->withQueryString(), 'accounts' => AccountingAccount::query()->orderBy('code')->get(['id', 'code', 'name'])]);
    }
    public function showJournal(JournalEntry $journalEntry): View
    {
        $this->authorizeOperation('accounting.journals.view', ['accounting_core']);
        return view('backend.operations.accounting.journal', ['journalEntry' => $journalEntry->load('lines.account')]);
    }
    public function postJournal(JournalEntry $journalEntry, AccountingPostingService $posting): RedirectResponse
    {
        $this->authorizeOperation('accounting.journals.post', ['accounting_core']);
        try { $posting->postJournalEntry($journalEntry, auth()->id()); } catch (DomainException $exception) { return back()->withErrors(['journal' => $exception->getMessage()]); }
        return back()->with('success', translate('Journal entry posted successfully'));
    }
    public function accountingEvents(Request $request, AccountingReportService $reports): View
    {
        $this->authorizeOperation('accounting.events.view', ['accounting_core', 'accounting_lite']);
        return view('backend.operations.accounting.events', ['events' => $reports->eventRows($request->only(['event_type', 'journal_posting_status', 'without_journal', 'from', 'to']))->paginate(30)->withQueryString()]);
    }
    public function postAccountingEvent(AccountingEvent $event, AccountingPostingService $posting): RedirectResponse
    {
        $this->authorizeOperation('accounting.journals.post', ['accounting_core']);
        try { $posting->post($event, auth()->id()); } catch (DomainException $exception) { return back()->withErrors(['event' => $exception->getMessage()]); }
        return back()->with('success', translate('Accounting event posted successfully'));
    }
    public function generalLedger(Request $request, AccountingReportService $reports): View
    {
        $this->authorizeOperation('accounting.general_ledger.view', ['accounting_core']);
        return view('backend.operations.accounting.general-ledger', $reports->generalLedger($request->integer('account_id') ?: null, $request->only(['from', 'to'])) + ['accounts' => AccountingAccount::query()->orderBy('code')->get(['id', 'code', 'name'])]);
    }
    public function trialBalance(Request $request, AccountingReportService $reports): View
    {
        $this->authorizeOperation('accounting.trial_balance.view', ['accounting_core']);
        return view('backend.operations.accounting.trial-balance', $reports->trialBalance($request->only(['from', 'to'])));
    }
    public function profitLoss(Request $request, AccountingReportService $reports): View
    {
        $this->authorizeOperation('accounting.profit_loss.view', ['accounting_core', 'accounting_lite']);
        return view('backend.operations.accounting.profit-loss', $reports->profitLoss($request->only(['from', 'to'])));
    }
    public function vatSnapshots(Request $request, AccountingReportService $reports): View
    {
        $this->authorizeOperation('accounting.tax.view', ['accounting_core']);
        return view('backend.operations.accounting.vat-snapshots', ['snapshots' => $reports->vatSnapshotRows($request->only(['tax_type', 'tax_rate_id', 'source_type', 'price_mode', 'from', 'to']))->paginate(30)->withQueryString(), 'taxRates' => TaxRate::query()->orderBy('name')->get(['id', 'name'])]);
    }
    public function vatAudit(AccountingReportService $reports): View
    {
        $this->authorizeOperation('accounting.tax.audit', ['accounting_core']);
        return view('backend.operations.accounting.vat-audit', ['audit' => $reports->vatAuditSummary()]);
    }

    private function authorizeOperation(string $permission, array $features): void
    {
        $user = auth()->user();
        if (! $user || ($user->user_type !== 'admin' && ! $user->can($permission))) abort(403);
        if ($user->user_type !== 'admin' && ! collect($features)->contains(fn ($feature) => $this->features->enabled($feature))) abort(404);
    }

    private function authorizeDocumentPermission(string $permission): void
    {
        $user = auth()->user();
        if (! $user || ($user->user_type !== 'admin' && ! $user->can($permission))) {
            abort(403);
        }
    }

    private function authorizeSalesInvoice(Order $order): void
    {
        $this->authorizeDocumentPermission('sales_invoices.export');
        $user = auth()->user();
        if (
            $user->user_type !== 'admin'
            && $user->hasRole('cashier')
            && ! $user->hasAnyRole(['owner_general_manager', 'store_admin', 'accountant'])
            && (int) $order->cashier_id !== (int) $user->id
        ) {
            abort(403);
        }
    }

    private function deliveryDocumentResponse(
        Order $order,
        string $type,
        OperationsPdfService $pdf,
        CoreMarketDocumentTemplateService $templates
    ) {
        $this->authorizeDocumentPermission('delivery_notes.export');
        $user = auth()->user();
        $order->loadMissing('delivery');
        if (
            $user->user_type !== 'admin'
            && $user->hasRole('delivery_distribution')
            && ! $user->hasAnyRole(['owner_general_manager', 'store_admin'])
            && (int) $order->delivery?->delivery_user_id !== (int) $user->id
        ) {
            abort(403);
        }

        $data = $pdf->deliveryDocument($order, $type);
        $contents = PDF::loadView('backend.operations.pdf.delivery-document', $data, [], $templates->paperConfig($data['template']))->output();

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$type.'-'.$order->code.'.pdf"',
        ]);
    }

    private function operationsQuickActions(): array
    {
        $user = auth()->user();
        $owner = $user?->user_type === 'admin';
        $can = fn (string $permission): bool => $owner || (bool) $user?->can($permission);
        $canAll = fn (array $permissions): bool => $owner
            || collect($permissions)->every(fn (string $permission) => (bool) $user?->can($permission));
        $inventory = $this->features->enabled('inventory_pro') || $this->features->enabled('accounting_lite');
        $purchasing = $this->features->enabled('purchasing_suppliers');
        $accounting = $this->features->enabled('accounting_lite') || $this->features->enabled('accounting_core');
        $pos = $this->features->enabled('pos') && $this->features->enabled('cashbox_shifts');

        return collect([
            ['show' => $pos && $can('pos.view'), 'label' => 'POS Sale', 'description' => 'Start a cashier sale', 'route' => 'operations.pos'],
            ['show' => $can('deliveries.view') || $can('deliveries.view_all') || $can('deliveries.view_assigned'), 'label' => 'Delivery Board', 'description' => 'Assign and track order deliveries', 'route' => 'operations.deliveries.index'],
            ['show' => $purchasing && $can('purchase_orders.create'), 'label' => 'Purchase Stock', 'description' => 'Create a purchase order', 'route' => 'operations.purchase-orders.create'],
            ['show' => $purchasing && $canAll(['purchase_orders.view', 'purchase_orders.receive']), 'label' => 'Receive Purchase', 'description' => 'Select an order to receive', 'route' => 'operations.purchase-orders'],
            ['show' => $purchasing && $can('purchase_returns.create'), 'label' => 'Return to Supplier', 'description' => 'Create a purchase return', 'route' => 'operations.purchase-returns.create'],
            ['show' => $purchasing && $canAll(['suppliers.view', 'supplier_ledger.view', 'supplier_payments.create']), 'label' => 'Supplier Payment', 'description' => 'Choose a supplier and record payment', 'route' => 'operations.suppliers'],
            ['show' => $purchasing && $canAll(['suppliers.view', 'supplier_ledger.view']), 'label' => 'Supplier Statement', 'description' => 'Choose a supplier and export statement', 'route' => 'operations.suppliers'],
            ['show' => (bool) $user?->can('add_new_product'), 'label' => 'Add Product', 'description' => 'Create a catalog product', 'route' => 'products.create'],
            ['show' => $inventory && $can('inventory.stock.view'), 'label' => 'Manage Stock', 'description' => 'Review products and variants', 'route' => 'operations.inventory.stock'],
            ['show' => $can('price_lists.manage'), 'label' => 'Price Lists', 'description' => 'Manage customer pricing levels', 'route' => 'operations.price-lists.index'],
            ['show' => $inventory && $can('inventory.stock.adjust'), 'label' => 'Inventory Policy', 'description' => 'Review stock control rules', 'route' => 'operations.inventory.policy'],
            ['show' => $accounting && $can('accounting_summary.view'), 'label' => 'Accounting Reports', 'description' => 'Open operational reports', 'route' => 'operations.accounting.reports'],
            ['show' => app(\App\Services\CoreMarketCustomerReceivableService::class)->enabled() && $can('customer_receivables.view'), 'label' => 'Customer Receivables', 'description' => 'Review customer balances and payments', 'route' => 'operations.customer-receivables.index'],
        ])->where('show', true)->map(fn (array $action) => [
            'label' => $action['label'],
            'description' => $action['description'],
            'url' => route($action['route']),
        ])->values()->all();
    }

    private function supplierData(Request $request): array { return $request->validate(['name' => 'required|string|max:255', 'company_name' => 'nullable|string|max:255', 'contact_name' => 'nullable|string|max:255', 'phone' => 'nullable|string|max:100', 'email' => 'nullable|email|max:255', 'address' => 'nullable|string|max:2000', 'tax_number' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:2000', 'is_active' => 'nullable|boolean']) + ['is_active' => $request->boolean('is_active')]; }
}
