<?php

namespace App\Http\Controllers;

use App\Models\InventoryAdjustmentDocument;
use App\Models\ProductStock;
use App\Models\StockCount;
use App\Models\StoreBranch;
use App\Services\CoreMarketInventoryAdjustmentService;
use App\Services\CoreMarketInventoryPolicyService;
use App\Services\CoreMarketStockCountService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InventoryGovernanceController extends Controller
{
    public function adjustments(Request $request): View
    {
        $this->authorizeInventory('inventory.adjustments.view');
        $query = InventoryAdjustmentDocument::query()->with(['creator', 'branch'])->latest();
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('adjustment_type')) {
            $query->where('adjustment_type', $request->input('adjustment_type'));
        }

        return view('backend.operations.inventory.adjustments.index', [
            'documents' => $query->paginate(25)->withQueryString(),
        ]);
    }

    public function createAdjustment(Request $request): View
    {
        $this->authorizeInventory('inventory.adjustments.create');

        return $this->documentForm(
            $request->input('adjustment_type', 'stock_adjustment'),
            $request->integer('product_stock_id') ?: null
        );
    }

    public function createOpeningStock(Request $request): View
    {
        $this->authorizeInventory('inventory.opening_stock.create');

        return $this->documentForm('opening_stock', $request->integer('product_stock_id') ?: null);
    }

    public function storeAdjustment(
        Request $request,
        CoreMarketInventoryAdjustmentService $adjustments
    ): RedirectResponse {
        $type = $request->input('adjustment_type', 'stock_adjustment');
        $permission = $type === 'opening_stock'
            ? 'inventory.opening_stock.create'
            : 'inventory.adjustments.create';
        $this->authorizeInventory($permission);
        if ($type === 'emergency_adjustment') {
            $this->authorizeInventory('inventory.adjustments.emergency');
        }
        $data = $this->validateDocument($request);

        try {
            $document = $type === 'opening_stock'
                ? $adjustments->createOpeningStockDocument($data, auth()->user())
                : $adjustments->createAdjustmentDocument($data, auth()->user());
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['inventory_document' => $exception->getMessage()]);
        }

        return redirect()->route('operations.inventory.adjustments.show', $document)
            ->with('success', translate('Inventory document created.'));
    }

    public function showAdjustment(InventoryAdjustmentDocument $document): View
    {
        $this->authorizeInventory(
            $document->adjustment_type === 'opening_stock'
                ? 'inventory.opening_stock.view'
                : 'inventory.adjustments.view'
        );

        return view('backend.operations.inventory.adjustments.show', [
            'document' => $document->load(['items.productStock', 'creator', 'reviewer', 'poster', 'branch']),
        ]);
    }

    public function submitAdjustment(
        InventoryAdjustmentDocument $document,
        CoreMarketInventoryAdjustmentService $adjustments
    ): RedirectResponse {
        $this->authorizeInventory(
            $document->adjustment_type === 'opening_stock'
                ? 'inventory.opening_stock.create'
                : 'inventory.adjustments.create'
        );

        return $this->runDocumentAction(fn () => $adjustments->submitForApproval($document, auth()->user()));
    }

    public function approveAdjustment(
        InventoryAdjustmentDocument $document,
        CoreMarketInventoryAdjustmentService $adjustments
    ): RedirectResponse {
        $this->authorizeInventory('inventory.adjustments.approve');

        return $this->runDocumentAction(fn () => $adjustments->approve($document, auth()->user()));
    }

    public function rejectAdjustment(
        Request $request,
        InventoryAdjustmentDocument $document,
        CoreMarketInventoryAdjustmentService $adjustments
    ): RedirectResponse {
        $this->authorizeInventory('inventory.adjustments.approve');
        $data = $request->validate(['notes' => 'nullable|string|max:2000']);

        return $this->runDocumentAction(fn () => $adjustments->reject($document, auth()->user(), $data['notes'] ?? null));
    }

    public function postAdjustment(
        InventoryAdjustmentDocument $document,
        CoreMarketInventoryAdjustmentService $adjustments
    ): RedirectResponse {
        $permission = $document->adjustment_type === 'opening_stock'
            ? 'inventory.opening_stock.post'
            : 'inventory.adjustments.post';
        $this->authorizeInventory($permission);

        return $this->runDocumentAction(fn () => $adjustments->post($document, auth()->user()));
    }

    public function cancelAdjustment(
        InventoryAdjustmentDocument $document,
        CoreMarketInventoryAdjustmentService $adjustments
    ): RedirectResponse {
        $this->authorizeInventory(
            $document->adjustment_type === 'opening_stock'
                ? 'inventory.opening_stock.create'
                : 'inventory.adjustments.cancel'
        );

        return $this->runDocumentAction(fn () => $adjustments->cancel($document, auth()->user()));
    }

    public function stockCounts(Request $request): View
    {
        $this->authorizeInventory('inventory.stock_counts.view');
        $query = StockCount::query()->with('branch')->latest();
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return view('backend.operations.inventory.stock-counts.index', [
            'stockCounts' => $query->paginate(25)->withQueryString(),
        ]);
    }

    public function createStockCount(): View
    {
        $this->authorizeInventory('inventory.stock_counts.create');

        return view('backend.operations.inventory.stock-counts.form', [
            'stocks' => $this->stocks(),
            'branches' => $this->branches(),
        ]);
    }

    public function storeStockCount(Request $request, CoreMarketStockCountService $counts): RedirectResponse
    {
        $this->authorizeInventory('inventory.stock_counts.create');
        $data = $request->validate([
            'branch_id' => 'nullable|integer|exists:store_branches,id',
            'product_stock_id' => 'required|integer|exists:product_stocks,id',
            'counted_quantity' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:2000',
        ]);

        try {
            $count = $counts->createStockCount([
                'branch_id' => $data['branch_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'items' => [[
                    'product_stock_id' => $data['product_stock_id'],
                    'counted_quantity' => $data['counted_quantity'],
                ]],
            ], auth()->user());
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['stock_count' => $exception->getMessage()]);
        }

        return redirect()->route('operations.inventory.stock-counts.show', $count)
            ->with('success', translate('Stock count created.'));
    }

    public function showStockCount(StockCount $stockCount): View
    {
        $this->authorizeInventory('inventory.stock_counts.view');

        return view('backend.operations.inventory.stock-counts.show', [
            'stockCount' => $stockCount->load(['items.productStock', 'branch']),
        ]);
    }

    public function submitStockCount(StockCount $stockCount, CoreMarketStockCountService $counts): RedirectResponse
    {
        $this->authorizeInventory('inventory.stock_counts.create');

        return $this->runDocumentAction(fn () => $counts->submitForApproval($stockCount, auth()->user()));
    }

    public function approveStockCount(StockCount $stockCount, CoreMarketStockCountService $counts): RedirectResponse
    {
        $this->authorizeInventory('inventory.stock_counts.approve');

        return $this->runDocumentAction(fn () => $counts->approve($stockCount, auth()->user()));
    }

    public function postStockCount(StockCount $stockCount, CoreMarketStockCountService $counts): RedirectResponse
    {
        $this->authorizeInventory('inventory.stock_counts.post');

        return $this->runDocumentAction(fn () => $counts->postVarianceAsAdjustment($stockCount, auth()->user()));
    }

    public function cancelStockCount(StockCount $stockCount, CoreMarketStockCountService $counts): RedirectResponse
    {
        $this->authorizeInventory('inventory.stock_counts.cancel');

        return $this->runDocumentAction(fn () => $counts->cancel($stockCount, auth()->user()));
    }

    private function documentForm(string $type, ?int $selectedStockId): View
    {
        return view('backend.operations.inventory.adjustments.form', [
            'adjustmentType' => $type,
            'selectedStockId' => $selectedStockId,
            'stocks' => $this->stocks(),
            'branches' => $this->branches(),
            'policy' => app(CoreMarketInventoryPolicyService::class)->policySnapshot(),
        ]);
    }

    private function validateDocument(Request $request): array
    {
        $data = $request->validate([
            'adjustment_type' => 'required|string|in:opening_stock,stock_adjustment,damage,loss,theft,internal_use,correction,emergency_adjustment,supplier_bonus,expired_goods,sample',
            'branch_id' => 'nullable|integer|exists:store_branches,id',
            'product_stock_id' => 'required|integer|exists:product_stocks,id',
            'quantity_change' => 'required|numeric|not_in:0',
            'unit_cost' => 'nullable|numeric|min:0',
            'reason' => 'required|string|max:255',
            'notes' => 'nullable|string|max:2000',
            'idempotency_key' => 'required|string|max:150',
        ]);
        $data['items'] = [[
            'product_stock_id' => $data['product_stock_id'],
            'quantity_change' => $data['quantity_change'],
            'unit_cost' => $data['unit_cost'] ?? null,
            'reason' => $data['reason'],
        ]];

        return $data;
    }

    private function runDocumentAction(callable $action): RedirectResponse
    {
        try {
            $action();
        } catch (DomainException $exception) {
            return back()->withErrors(['inventory_document' => $exception->getMessage()]);
        }

        return back()->with('success', translate('Inventory document updated.'));
    }

    private function authorizeInventory(string $permission): void
    {
        $user = auth()->user();
        if (! $user || ($user->user_type !== 'admin' && ! $user->can($permission))) {
            abort(403);
        }
        if (! coremarket_feature_enabled('inventory_pro')) {
            abort(404);
        }
    }

    private function stocks()
    {
        return ProductStock::query()->with('product')->orderBy('product_id')->limit(1000)->get();
    }

    private function branches()
    {
        return StoreBranch::query()->where('is_active', true)->orderBy('name')->get();
    }
}
