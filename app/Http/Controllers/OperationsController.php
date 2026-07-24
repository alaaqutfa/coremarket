<?php

namespace App\Http\Controllers;

use App\Models\AccountingEvent;
use App\Models\AccountingAccount;
use App\Models\BusinessSetting;
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
use App\Services\AccountingPostingService;
use App\Services\AccountingReportService;
use App\Services\AccountingEventService;
use App\Services\AccountingSummaryService;
use App\Services\CoreMarketFeatureAccessService;
use App\Services\CoreMarketInventoryPolicyService;
use App\Services\CoreMarketTaxService;
use App\Services\InventoryProService;
use App\Services\OperationsPdfService;
use App\Services\ProductIdentityLookupService;
use App\Services\PurchaseItemPricingService;
use App\Services\PurchaseReceivingService;
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
            'movementCount' => InventoryMovement::count(),
            'recentProductCount' => InventoryMovement::query()->where('created_at', '>=', now()->subDays(30))->distinct('product_id')->count('product_id'),
            'openPurchaseOrders' => PurchaseOrder::query()->whereIn('status', ['draft', 'ordered', 'partially_received'])->count(),
            'activeSalesReturns' => SalesReturn::query()->whereIn('status', ['draft', 'requested', 'approved'])->count(),
            'monthExpenses' => Expense::query()->whereIn('status', ['approved', 'paid'])->whereBetween('expense_date', [now()->startOfMonth(), now()->endOfMonth()])->sum('amount'),
            'summary' => app(AccountingSummaryService::class)->summary(),
        ]);
    }

    public function inventoryMovements(Request $request): View
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

        return view('backend.operations.inventory-movements', [
            'movements' => $query->paginate(30)->withQueryString(),
            'products' => Product::query()->orderBy('name')->limit(250)->get(),
            'families' => ProductFamily::query()->families()->active()->with(['children' => fn ($children) => $children->active()])->orderBy('name')->get(),
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

    public function inventoryPolicy(CoreMarketInventoryPolicyService $policy): View
    {
        $this->authorizeOperation('inventory.stock.adjust', ['inventory_pro']);

        return view('backend.operations.inventory.policy', ['policy' => $policy->policySnapshot()]);
    }

    public function updateInventoryPolicy(Request $request): RedirectResponse
    {
        $this->authorizeOperation('inventory.stock.adjust', ['inventory_pro']);
        $data = $request->validate([
            'strict_inventory_mode' => 'required|boolean',
            'allow_negative_stock' => 'required|boolean',
        ]);

        foreach ([
            CoreMarketInventoryPolicyService::STRICT_MODE_SETTING => $data['strict_inventory_mode'],
            CoreMarketInventoryPolicyService::NEGATIVE_STOCK_SETTING => $data['allow_negative_stock'],
        ] as $type => $value) {
            $setting = BusinessSetting::query()->where('type', $type)->whereNull('lang')->first() ?: new BusinessSetting();
            $setting->forceFill(['type' => $type, 'value' => $value ? '1' : '0', 'lang' => null])->save();
        }
        Cache::forget('business_settings');

        return back()->with('success', translate('Inventory policy updated successfully'));
    }

    public function adjustStockForm(ProductStock $productStock): View
    {
        $this->authorizeOperation('inventory.stock.adjust', ['inventory_pro']);
        return view('backend.operations.inventory.adjust', ['productStock' => $productStock->load('product')]);
    }

    public function adjustStock(Request $request, ProductStock $productStock, InventoryProService $inventory): RedirectResponse
    {
        $this->authorizeOperation('inventory.stock.adjust', ['inventory_pro']);
        $data = $request->validate(['adjustment_type' => 'required|in:increase,decrease,set', 'quantity' => 'required|numeric|min:0', 'reason' => 'required|string|max:255', 'notes' => 'nullable|string|max:2000']);
        try {
            $inventory->adjustStock($productStock, $data, auth()->id());
        } catch (DomainException $exception) {
            return back()->withErrors(['quantity' => $exception->getMessage()])->withInput();
        }
        return redirect()->route('operations.inventory.stock')->with('success', translate('Stock adjustment recorded successfully'));
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
    public function supplierStatementPdf(Request $request, Supplier $supplier, OperationsPdfService $pdf)
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

        $contents = PDF::loadView('backend.operations.pdf.supplier-statement', $data, [], [
            'format' => 'A4',
        ])->output();

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="supplier-statement-'.$supplier->id.'.pdf"',
        ]);
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
    public function createPurchaseOrder(CoreMarketTaxService $tax): View
    {
        $this->authorizeOperation('purchase_orders.create', ['purchasing_suppliers']);
        return view('backend.operations.purchase-orders.form', [
            'suppliers' => Supplier::query()->where('is_active', true)->orderBy('name')->get(),
            'products' => Product::query()->orderBy('name')->limit(500)->get(),
            'productStocks' => ProductStock::query()->with('product')->orderBy('product_id')->get(),
            'defaultTaxRate' => $tax->getDefaultTaxRate(),
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
    public function showPurchaseOrder(PurchaseOrder $purchaseOrder, PurchasingUiService $purchasing): View
    {
        $this->authorizeOperation('purchase_orders.view', ['purchasing_suppliers']);
        $purchaseOrder->load(['supplier', 'items.product', 'items.productStock', 'receipts.items.purchaseOrderItem']);
        $movementIds = $purchaseOrder->receipts->flatMap->items->pluck('inventory_movement_id')->filter();

        return view('backend.operations.purchase-orders.show', [
            'purchaseOrder' => $purchaseOrder,
            'progress' => $purchasing->progress($purchaseOrder),
            'movements' => InventoryMovement::query()->with(['product', 'productStock'])->whereIn('id', $movementIds)->latest()->get(),
        ]);
    }
    public function purchaseOrderPdf(PurchaseOrder $purchaseOrder, OperationsPdfService $pdf)
    {
        $this->authorizeOperation('purchase_orders.view', ['purchasing_suppliers']);
        $data = $pdf->purchaseDocument($purchaseOrder);

        $contents = PDF::loadView('backend.operations.pdf.purchase-document', $data, [], [
            'format' => 'A4',
        ])->output();

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="purchase-'.$purchaseOrder->purchase_number.'.pdf"',
        ]);
    }
    public function receivePurchaseOrder(Request $request, PurchaseOrder $purchaseOrder, PurchaseReceivingService $service): RedirectResponse
    {
        $this->authorizeOperation('purchase_orders.receive', ['purchasing_suppliers']);
        $data = $request->validate(['receipt_key' => 'required|string|max:100', 'notes' => 'nullable|string|max:2000', 'items' => 'required|array|min:1', 'items.*.purchase_order_item_id' => 'required|exists:purchase_order_items,id', 'items.*.quantity_received' => 'required|numeric|min:0', 'items.*.unit_cost' => 'nullable|numeric|min:0']);
        $items = collect($data['items'])->filter(fn ($item) => (float) $item['quantity_received'] > 0)->values()->all();
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
    public function purchaseReceiptPdf(PurchaseReceipt $purchaseReceipt, OperationsPdfService $pdf)
    {
        $this->authorizeOperation('purchase_orders.view', ['purchasing_suppliers']);
        $data = $pdf->purchaseDocument($purchaseReceipt->purchaseOrder, $purchaseReceipt);

        $contents = PDF::loadView('backend.operations.pdf.purchase-document', $data, [], [
            'format' => 'A4',
        ])->output();

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

    public function createPurchaseReturn(Request $request): View
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
        ]);
    }

    public function storePurchaseReturn(Request $request, PurchaseReturnService $service): RedirectResponse
    {
        $this->authorizeOperation('purchase_returns.create', ['purchasing_suppliers']);
        $data = $request->validate([
            'purchase_order_id' => 'required|exists:purchase_orders,id',
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
        $data = $request->validate(['order_id' => 'required|exists:orders,id', 'reason' => 'nullable|string|max:1000', 'notes' => 'nullable|string|max:2000', 'items' => 'required|array|min:1', 'items.*.order_detail_id' => 'required|exists:order_details,id', 'items.*.quantity' => 'required|numeric|min:0', 'items.*.reason' => 'nullable|string|max:1000']);
        $items = collect($data['items'])->filter(fn ($item) => (float) $item['quantity'] > 0)->values()->all();
        if (empty($items)) return back()->withErrors(['items' => translate('Enter a quantity to return.')]);
        try {
            $return = $service->create(Order::findOrFail($data['order_id']), $items, $data, auth()->id());
        } catch (DomainException $exception) {
            return back()->withErrors(['items' => $exception->getMessage()])->withInput();
        }
        return redirect()->route('operations.sales-returns.show', $return)->with('success', translate('Sales return created successfully'));
    }
    public function showSalesReturn(SalesReturn $salesReturn, SalesReturnUiService $returns): View
    {
        $this->authorizeOperation('sales_returns.view', ['returns_management']);
        $salesReturn->load(['order.user', 'items.product', 'items.productStock', 'items.orderDetail']);
        return view('backend.operations.sales-returns.show', [
            'salesReturn' => $salesReturn,
            'movements' => $returns->linkedMovements($salesReturn),
            'accountingEvents' => $returns->accountingEvents($salesReturn),
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

    public function expenses(): View { $this->authorizeOperation('expenses.view', ['accounting_lite']); return view('backend.operations.expenses.index', ['expenses' => Expense::with('category')->latest()->paginate(25)]); }
    public function createExpense(): View { $this->authorizeOperation('expenses.create', ['accounting_lite']); return view('backend.operations.expenses.form', ['categories' => ExpenseCategory::query()->where('is_active', true)->orderBy('name')->get()]); }
    public function storeExpense(Request $request): RedirectResponse { $this->authorizeOperation('expenses.create', ['accounting_lite']); $data = $request->validate(['expense_category_id' => 'nullable|exists:expense_categories,id', 'title' => 'required|string|max:255', 'amount' => 'required|numeric|min:0', 'currency' => 'nullable|string|max:10', 'expense_date' => 'nullable|date', 'payment_method' => 'nullable|string|max:100', 'vendor_name' => 'nullable|string|max:255', 'reference_number' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:2000']); $expense = Expense::create(array_merge($data, ['status' => 'draft', 'created_by' => auth()->id()])); return redirect()->route('operations.expenses.show', $expense)->with('success', translate('Expense created successfully')); }
    public function showExpense(Expense $expense): View { $this->authorizeOperation('expenses.view', ['accounting_lite']); return view('backend.operations.expenses.show', compact('expense')); }
    public function approveExpense(Expense $expense, AccountingEventService $service): RedirectResponse { $this->authorizeOperation('expenses.approve', ['accounting_lite']); $service->approveExpense($expense, auth()->id()); return back()->with('success', translate('Expense approved successfully')); }
    public function accountingSummary(): View { $this->authorizeOperation('accounting_summary.view', ['accounting_lite']); return view('backend.operations.accounting-summary', ['summary' => app(AccountingSummaryService::class)->summary()]); }
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
    private function supplierData(Request $request): array { return $request->validate(['name' => 'required|string|max:255', 'company_name' => 'nullable|string|max:255', 'contact_name' => 'nullable|string|max:255', 'phone' => 'nullable|string|max:100', 'email' => 'nullable|email|max:255', 'address' => 'nullable|string|max:2000', 'tax_number' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:2000', 'is_active' => 'nullable|boolean']) + ['is_active' => $request->boolean('is_active')]; }
}
