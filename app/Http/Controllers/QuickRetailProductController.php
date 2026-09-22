<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductStockBranchBalance;
use App\Services\CoreMarketBranchInventoryService;
use App\Services\CoreMarketRetailWorkspaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class QuickRetailProductController extends Controller
{
    public function __construct(private CoreMarketRetailWorkspaceService $workspace, private CoreMarketBranchInventoryService $inventory)
    {
    }

    public function create(): View { return $this->form(new Product()); }

    public function edit(Product $product): View { return $this->form($product->load('stocks')); }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request, new Product());
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        return $this->save($request, $product->load('stocks'));
    }

    private function form(Product $product): View
    {
        $this->authorizeQuickProduct();
        return view('backend.product.products.quick-form', [
            'product' => $product,
            'stock' => $product->stocks->first(),
            'categories' => Category::query()->where('digital', 0)->where('parent_id', 0)->with('childrenCategories')->get(),
        ]);
    }

    private function save(Request $request, Product $product): RedirectResponse
    {
        $this->authorizeQuickProduct();
        $data = $request->validate([
            'name' => 'required|string|max:255', 'category_id' => 'required|exists:categories,id',
            'category_ids' => 'nullable|array', 'category_ids.*' => 'integer|exists:categories,id',
            'sku' => ['required', 'string', 'max:255', Rule::unique('product_stocks', 'sku')->ignore($product->stocks->first()?->id)],
            'barcode' => ['nullable', 'string', 'max:255', Rule::unique('products', 'barcode')->ignore($product->id)],
            'thumbnail_img' => 'nullable|integer', 'unit_price' => 'required|numeric|min:0',
            'purchase_price' => 'nullable|numeric|min:0',
            'current_stock' => 'required|numeric|min:0', 'low_stock_quantity' => 'nullable|numeric|min:0', 'published' => 'nullable|boolean',
        ]);

        DB::transaction(function () use ($product, $data) {
            $isNew = ! $product->exists;
            $product->forceFill([
                'name' => $data['name'], 'slug' => $product->slug ?: Str::slug($data['name']).'-'.Str::lower(Str::random(5)),
                'added_by' => 'admin', 'user_id' => auth()->id(), 'category_id' => $data['category_id'],
                'unit_price' => $data['unit_price'],
                'purchase_price' => $data['purchase_price'] ?? 0, 'unit' => 'pc', 'barcode' => $data['barcode'] ?? null,
                'thumbnail_img' => $data['thumbnail_img'] ?? null, 'published' => (bool) ($data['published'] ?? true),
                'approved' => 1,
                'low_stock_quantity' => $data['low_stock_quantity'] ?? null, 'current_stock' => $data['current_stock'],
            ] + ($isNew ? [
                'digital' => 0, 'auction_product' => 0, 'wholesale_product' => 0,
                'variant_product' => 0, 'attributes' => '[]', 'choice_options' => '[]', 'colors' => '[]', 'variations' => '[]',
            ] : []))->save();
            $product->categories()->sync(array_unique(array_merge([$data['category_id']], $data['category_ids'] ?? [])));
            $stock = $product->stocks()->firstOrNew(['variant' => '']);
            $stock->forceFill(['sku' => $data['sku'], 'barcode' => $data['barcode'] ?? null, 'price' => $data['unit_price'], 'qty' => $data['current_stock']])->save();
            if ($this->inventory->branchInventoryEnabled()) {
                $branch = $this->inventory->resolveBranchForUser(auth()->user());
                ProductStockBranchBalance::query()->updateOrCreate(
                    ['product_stock_id' => $stock->id, 'store_branch_id' => $branch->id],
                    ['product_id' => $product->id, 'quantity' => $data['current_stock'], 'reserved_quantity' => 0, 'last_movement_at' => now()]
                );
            }
        });

        return redirect()->route('products.admin')->with('success', translate('Product saved successfully'));
    }

    private function authorizeQuickProduct(): void
    {
        abort_unless($this->workspace->isQuickRetailMode(auth()->user()) && (auth()->user()->user_type === 'admin' || auth()->user()->can('add_new_product')), 403);
    }
}
