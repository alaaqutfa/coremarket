<?php

namespace App\Http\Controllers;

use App\Models\AccountingEvent;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesReturn;
use App\Models\Supplier;
use App\Services\AccountingEventService;
use App\Services\AccountingSummaryService;
use App\Services\CoreMarketFeatureAccessService;
use App\Services\PurchaseReceivingService;
use App\Services\SalesReturnService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

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
        $query = InventoryMovement::query()->with(['product', 'productStock', 'order'])->latest();
        foreach (['movement_type', 'direction', 'product_id'] as $filter) {
            if ($request->filled($filter)) $query->where($filter, $request->input($filter));
        }
        if ($request->filled('from')) $query->whereDate('created_at', '>=', $request->input('from'));
        if ($request->filled('to')) $query->whereDate('created_at', '<=', $request->input('to'));

        return view('backend.operations.inventory-movements', ['movements' => $query->paginate(30)->withQueryString(), 'products' => Product::query()->orderBy('name')->limit(250)->get()]);
    }

    public function suppliers(): View { $this->authorizeOperation('suppliers.view', ['purchasing_suppliers']); return view('backend.operations.suppliers.index', ['suppliers' => Supplier::latest()->paginate(25)]); }
    public function createSupplier(): View { $this->authorizeOperation('suppliers.create', ['purchasing_suppliers']); return view('backend.operations.suppliers.form', ['supplier' => new Supplier()]); }
    public function editSupplier(Supplier $supplier): View { $this->authorizeOperation('suppliers.edit', ['purchasing_suppliers']); return view('backend.operations.suppliers.form', compact('supplier')); }
    public function storeSupplier(Request $request): RedirectResponse { $this->authorizeOperation('suppliers.create', ['purchasing_suppliers']); $supplier = Supplier::create($this->supplierData($request)); return redirect()->route('operations.suppliers.edit', $supplier)->with('success', translate('Supplier saved successfully')); }
    public function updateSupplier(Request $request, Supplier $supplier): RedirectResponse { $this->authorizeOperation('suppliers.edit', ['purchasing_suppliers']); $supplier->update($this->supplierData($request)); return back()->with('success', translate('Supplier saved successfully')); }

    public function purchaseOrders(): View { $this->authorizeOperation('purchase_orders.view', ['purchasing_suppliers']); return view('backend.operations.purchase-orders.index', ['purchaseOrders' => PurchaseOrder::with('supplier')->latest()->paginate(25)]); }
    public function createPurchaseOrder(): View { $this->authorizeOperation('purchase_orders.create', ['purchasing_suppliers']); return view('backend.operations.purchase-orders.form', ['suppliers' => Supplier::query()->where('is_active', true)->orderBy('name')->get(), 'products' => Product::query()->orderBy('name')->limit(500)->get()]); }
    public function storePurchaseOrder(Request $request, PurchaseReceivingService $service): RedirectResponse
    {
        $this->authorizeOperation('purchase_orders.create', ['purchasing_suppliers']);
        $data = $request->validate(['supplier_id' => 'nullable|exists:suppliers,id', 'ordered_at' => 'nullable|date', 'currency' => 'nullable|string|max:10', 'notes' => 'nullable|string|max:2000', 'items' => 'required|array|min:1', 'items.*.product_id' => 'required|exists:products,id', 'items.*.product_stock_id' => 'nullable|exists:product_stocks,id', 'items.*.variant' => 'nullable|string|max:255', 'items.*.quantity_ordered' => 'required|numeric|min:0.000001', 'items.*.unit_cost' => 'nullable|numeric|min:0']);
        $order = $service->createPurchaseOrder($data, $data['items'], auth()->id());
        return redirect()->route('operations.purchase-orders.show', $order)->with('success', translate('Purchase order created successfully'));
    }
    public function showPurchaseOrder(PurchaseOrder $purchaseOrder): View { $this->authorizeOperation('purchase_orders.view', ['purchasing_suppliers']); return view('backend.operations.purchase-orders.show', ['purchaseOrder' => $purchaseOrder->load('supplier', 'items')]); }
    public function receivePurchaseOrder(Request $request, PurchaseOrder $purchaseOrder, PurchaseReceivingService $service): RedirectResponse
    {
        $this->authorizeOperation('purchase_orders.receive', ['purchasing_suppliers']);
        $data = $request->validate(['receipt_key' => 'required|string|max:100', 'notes' => 'nullable|string|max:2000', 'items' => 'required|array|min:1', 'items.*.purchase_order_item_id' => 'required|exists:purchase_order_items,id', 'items.*.quantity_received' => 'required|numeric|min:0', 'items.*.unit_cost' => 'nullable|numeric|min:0']);
        $items = collect($data['items'])->filter(fn ($item) => (float) $item['quantity_received'] > 0)->values()->all();
        if (empty($items)) return back()->withErrors(['items' => translate('Enter a quantity to receive.')]);
        $service->receive($purchaseOrder, $items, $data, auth()->id());
        return back()->with('success', translate('Stock received successfully'));
    }

    public function salesReturns(): View { $this->authorizeOperation('sales_returns.view', ['returns_management']); return view('backend.operations.sales-returns.index', ['salesReturns' => SalesReturn::with('order')->latest()->paginate(25)]); }
    public function createSalesReturn(Request $request): View { $this->authorizeOperation('sales_returns.create', ['returns_management']); $order = $request->filled('order_id') ? Order::with('orderDetails')->findOrFail($request->integer('order_id')) : null; return view('backend.operations.sales-returns.form', ['orders' => Order::latest()->limit(100)->get(), 'order' => $order]); }
    public function storeSalesReturn(Request $request, SalesReturnService $service): RedirectResponse
    {
        $this->authorizeOperation('sales_returns.create', ['returns_management']);
        $data = $request->validate(['order_id' => 'required|exists:orders,id', 'reason' => 'nullable|string|max:1000', 'notes' => 'nullable|string|max:2000', 'items' => 'required|array|min:1', 'items.*.order_detail_id' => 'required|exists:order_details,id', 'items.*.quantity' => 'required|numeric|min:0', 'items.*.reason' => 'nullable|string|max:1000']);
        $items = collect($data['items'])->filter(fn ($item) => (float) $item['quantity'] > 0)->values()->all();
        if (empty($items)) return back()->withErrors(['items' => translate('Enter a quantity to return.')]);
        $return = $service->create(Order::findOrFail($data['order_id']), $items, $data, auth()->id());
        return redirect()->route('operations.sales-returns.show', $return)->with('success', translate('Sales return created successfully'));
    }
    public function showSalesReturn(SalesReturn $salesReturn): View { $this->authorizeOperation('sales_returns.view', ['returns_management']); return view('backend.operations.sales-returns.show', ['salesReturn' => $salesReturn->load('order', 'items')]); }
    public function completeSalesReturn(SalesReturn $salesReturn, SalesReturnService $service): RedirectResponse { $this->authorizeOperation('sales_returns.complete', ['returns_management']); $service->complete($salesReturn, auth()->id()); return back()->with('success', translate('Sales return completed successfully')); }

    public function expenses(): View { $this->authorizeOperation('expenses.view', ['accounting_lite']); return view('backend.operations.expenses.index', ['expenses' => Expense::with('category')->latest()->paginate(25)]); }
    public function createExpense(): View { $this->authorizeOperation('expenses.create', ['accounting_lite']); return view('backend.operations.expenses.form', ['categories' => ExpenseCategory::query()->where('is_active', true)->orderBy('name')->get()]); }
    public function storeExpense(Request $request): RedirectResponse { $this->authorizeOperation('expenses.create', ['accounting_lite']); $data = $request->validate(['expense_category_id' => 'nullable|exists:expense_categories,id', 'title' => 'required|string|max:255', 'amount' => 'required|numeric|min:0', 'currency' => 'nullable|string|max:10', 'expense_date' => 'nullable|date', 'payment_method' => 'nullable|string|max:100', 'vendor_name' => 'nullable|string|max:255', 'reference_number' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:2000']); $expense = Expense::create(array_merge($data, ['status' => 'draft', 'created_by' => auth()->id()])); return redirect()->route('operations.expenses.show', $expense)->with('success', translate('Expense created successfully')); }
    public function showExpense(Expense $expense): View { $this->authorizeOperation('expenses.view', ['accounting_lite']); return view('backend.operations.expenses.show', compact('expense')); }
    public function approveExpense(Expense $expense, AccountingEventService $service): RedirectResponse { $this->authorizeOperation('expenses.approve', ['accounting_lite']); $service->approveExpense($expense, auth()->id()); return back()->with('success', translate('Expense approved successfully')); }
    public function accountingSummary(): View { $this->authorizeOperation('accounting_summary.view', ['accounting_lite']); return view('backend.operations.accounting-summary', ['summary' => app(AccountingSummaryService::class)->summary()]); }

    private function authorizeOperation(string $permission, array $features): void
    {
        $user = auth()->user();
        if (! $user || ($user->user_type !== 'admin' && ! $user->can($permission))) abort(403);
        if ($user->user_type !== 'admin' && ! collect($features)->contains(fn ($feature) => $this->features->enabled($feature))) abort(404);
    }
    private function supplierData(Request $request): array { return $request->validate(['name' => 'required|string|max:255', 'company_name' => 'nullable|string|max:255', 'contact_name' => 'nullable|string|max:255', 'phone' => 'nullable|string|max:100', 'email' => 'nullable|email|max:255', 'address' => 'nullable|string|max:2000', 'tax_number' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:2000', 'is_active' => 'nullable|boolean']) + ['is_active' => $request->boolean('is_active')]; }
}
