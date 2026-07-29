<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductBranchPrice;
use App\Models\ProductStock;
use App\Services\CoreMarketBranchInventoryService;
use App\Services\CoreMarketBranchPricingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BranchPriceController extends Controller
{
    public function index(
        Request $request,
        CoreMarketBranchInventoryService $branches,
        CoreMarketBranchPricingService $pricing
    ): View {
        $this->authorizeAction('pricing.branch_prices.view', $pricing);
        $visibleBranches = $branches->visibleBranches(auth()->user());
        $branchId = $request->integer('branch_id') ?: $visibleBranches->first()?->id;
        if ($branchId) {
            $branches->resolveBranchForOperation($branchId, auth()->user());
        }

        $prices = ProductBranchPrice::query()
            ->with(['branch', 'product', 'productStock'])
            ->whereIn('store_branch_id', $visibleBranches->pluck('id'))
            ->when($branchId, fn ($query) => $query->where('store_branch_id', $branchId))
            ->when($request->filled('q'), function ($query) use ($request) {
                $search = trim((string) $request->input('q'));
                $query->where(fn ($scope) => $scope
                    ->whereHas('product', fn ($product) => $product->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('productStock', fn ($stock) => $stock
                        ->where('sku', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%")));
            })
            ->latest()
            ->paginate(30)
            ->withQueryString();

        return view('backend.operations.pricing.branch-prices.index', [
            'prices' => $prices,
            'branches' => $visibleBranches,
            'canManage' => $this->canManage(),
        ]);
    }

    public function create(
        CoreMarketBranchInventoryService $branches,
        CoreMarketBranchPricingService $pricing
    ): View {
        $this->authorizeAction('pricing.branch_prices.manage', $pricing);

        return $this->form(new ProductBranchPrice(), $branches);
    }

    public function store(
        Request $request,
        CoreMarketBranchInventoryService $branches,
        CoreMarketBranchPricingService $pricing
    ): RedirectResponse {
        $this->authorizeAction('pricing.branch_prices.manage', $pricing);
        $data = $this->validated($request, $branches);

        ProductBranchPrice::query()->updateOrCreate(
            [
                'store_branch_id' => $data['store_branch_id'],
                'product_id' => $data['product_id'],
                'product_stock_id' => $data['product_stock_id'] ?? null,
            ],
            $data
        );

        return redirect()->route('operations.branch-prices.index')
            ->with('success', translate('Branch price saved successfully.'));
    }

    public function edit(
        ProductBranchPrice $branchPrice,
        CoreMarketBranchInventoryService $branches,
        CoreMarketBranchPricingService $pricing
    ): View {
        $this->authorizeAction('pricing.branch_prices.manage', $pricing);
        $branches->resolveBranchForOperation($branchPrice->store_branch_id, auth()->user());

        return $this->form($branchPrice, $branches);
    }

    public function update(
        Request $request,
        ProductBranchPrice $branchPrice,
        CoreMarketBranchInventoryService $branches,
        CoreMarketBranchPricingService $pricing
    ): RedirectResponse {
        $this->authorizeAction('pricing.branch_prices.manage', $pricing);
        $branches->resolveBranchForOperation($branchPrice->store_branch_id, auth()->user());
        $data = $this->validated($request, $branches);

        $duplicate = ProductBranchPrice::query()
            ->where('store_branch_id', $data['store_branch_id'])
            ->where('product_id', $data['product_id'])
            ->where('product_stock_id', $data['product_stock_id'] ?? null)
            ->whereKeyNot($branchPrice->id)
            ->exists();
        if ($duplicate) {
            return back()->withInput()->withErrors([
                'product_stock_id' => translate('A branch price already exists for this product or variant.'),
            ]);
        }

        $branchPrice->update($data);

        return redirect()->route('operations.branch-prices.index')
            ->with('success', translate('Branch price updated successfully.'));
    }

    private function form(
        ProductBranchPrice $branchPrice,
        CoreMarketBranchInventoryService $branches
    ): View {
        return view('backend.operations.pricing.branch-prices.form', [
            'branchPrice' => $branchPrice,
            'branches' => $branches->visibleBranches(auth()->user()),
            'products' => Product::query()->with('stocks')->orderBy('name')->limit(1000)->get(),
        ]);
    }

    private function validated(
        Request $request,
        CoreMarketBranchInventoryService $branches
    ): array {
        $data = $request->validate([
            'store_branch_id' => ['required', 'integer', 'exists:store_branches,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'product_stock_id' => ['required', 'integer', 'exists:product_stocks,id'],
            'price' => ['required', 'numeric', 'min:0'],
            'compare_at_price' => ['nullable', 'numeric', 'min:0'],
            'margin_percent' => ['nullable', 'numeric'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $branches->resolveBranchForOperation($data['store_branch_id'], auth()->user());
        ProductStock::query()
            ->whereKey($data['product_stock_id'])
            ->where('product_id', $data['product_id'])
            ->firstOrFail();
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }

    private function authorizeAction(
        string $permission,
        CoreMarketBranchPricingService $pricing
    ): void {
        $user = auth()->user();
        if (! $user || ($user->user_type !== 'admin' && ! $user->can($permission))) {
            abort(403);
        }
        if ($user->user_type !== 'admin' && ! $pricing->branchPricingEnabled()) {
            abort(404, 'Branch pricing is disabled.');
        }
    }

    private function canManage(): bool
    {
        return auth()->user()?->user_type === 'admin'
            || auth()->user()?->can('pricing.branch_prices.manage');
    }
}
