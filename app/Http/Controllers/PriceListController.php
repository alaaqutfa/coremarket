<?php

namespace App\Http\Controllers;

use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PriceListController extends Controller
{
    public function index(): View
    {
        $this->authorizeManagement();

        return view('backend.operations.pricing.index', [
            'priceLists' => PriceList::query()->withCount(['items', 'customers'])->orderByDesc('is_default')->orderBy('name')->paginate(20),
        ]);
    }

    public function create(): View
    {
        $this->authorizeManagement();

        return view('backend.operations.pricing.form', ['priceList' => new PriceList()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManagement();
        $priceList = DB::transaction(function () use ($request) {
            $data = $this->validatedPriceList($request);
            $this->unsetOtherDefaults($data['is_default']);

            return PriceList::query()->create($data);
        });

        flash(translate('Price list created successfully'))->success();

        return redirect()->route('operations.price-lists.show', $priceList);
    }

    public function show(PriceList $priceList): View
    {
        $this->authorizeManagement();

        return view('backend.operations.pricing.show', [
            'priceList' => $priceList->load(['items.product', 'items.productStock']),
            'products' => Product::query()->with('stocks')->orderBy('name')->get(),
            'customers' => User::query()->where('user_type', 'customer')->orderBy('name')->get(['id', 'name', 'email', 'price_list_id']),
        ]);
    }

    public function edit(PriceList $priceList): View
    {
        $this->authorizeManagement();

        return view('backend.operations.pricing.form', compact('priceList'));
    }

    public function update(Request $request, PriceList $priceList): RedirectResponse
    {
        $this->authorizeManagement();
        DB::transaction(function () use ($request, $priceList) {
            $data = $this->validatedPriceList($request, $priceList);
            $this->unsetOtherDefaults($data['is_default'], $priceList->id);
            $priceList->update($data);
        });

        flash(translate('Price list updated successfully'))->success();

        return redirect()->route('operations.price-lists.show', $priceList);
    }

    public function storeItem(Request $request, PriceList $priceList): RedirectResponse
    {
        $this->authorizeManagement();
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'product_stock_id' => ['nullable', 'integer', 'exists:product_stocks,id'],
            'fixed_price' => ['nullable', 'numeric', 'min:0'],
            'margin_percent' => ['nullable', 'numeric', 'min:0'],
            'discount_percent' => ['nullable', 'numeric', 'between:0,100'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (! empty($data['product_stock_id'])) {
            ProductStock::query()
                ->whereKey($data['product_stock_id'])
                ->where('product_id', $data['product_id'])
                ->firstOrFail();
        }

        $requiredField = match ($priceList->pricing_method) {
            'fixed_price' => 'fixed_price',
            'margin_over_cost' => 'margin_percent',
            'discount_from_regular' => 'discount_percent',
        };
        $defaultValue = $requiredField === 'margin_percent'
            ? $priceList->margin_percent
            : ($requiredField === 'discount_percent' ? $priceList->discount_percent : null);
        if (! is_numeric($data[$requiredField] ?? $defaultValue)) {
            return back()->withErrors([$requiredField => translate('This value is required for the selected pricing method.')])->withInput();
        }

        PriceListItem::query()->updateOrCreate(
            [
                'price_list_id' => $priceList->id,
                'product_id' => $data['product_id'],
                'product_stock_id' => $data['product_stock_id'] ?? null,
            ],
            array_merge($data, ['is_active' => $request->boolean('is_active')])
        );

        flash(translate('Price list item saved successfully'))->success();

        return back();
    }

    public function destroyItem(PriceList $priceList, PriceListItem $item): RedirectResponse
    {
        $this->authorizeManagement();
        abort_unless((int) $item->price_list_id === (int) $priceList->id, 404);
        $item->delete();
        flash(translate('Price list item deleted successfully'))->success();

        return back();
    }

    public function assignCustomer(Request $request, PriceList $priceList): RedirectResponse
    {
        $this->authorizeManagement();
        $data = $request->validate(['customer_id' => ['required', 'integer', 'exists:users,id']]);
        $customer = User::query()->whereKey($data['customer_id'])->where('user_type', 'customer')->firstOrFail();
        $customer->forceFill(['price_list_id' => $priceList->id])->save();
        flash(translate('Customer price list assigned successfully'))->success();

        return back();
    }

    private function validatedPriceList(Request $request, ?PriceList $priceList = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'code' => ['required', 'string', 'max:100', Rule::unique('price_lists', 'code')->ignore($priceList?->id)],
            'type' => ['required', Rule::in(['retail', 'wholesale', 'vip', 'custom'])],
            'pricing_method' => ['required', Rule::in(['fixed_price', 'margin_over_cost', 'discount_from_regular'])],
            'margin_percent' => ['nullable', 'numeric', 'min:0'],
            'discount_percent' => ['nullable', 'numeric', 'between:0,100'],
            'currency' => ['required', 'string', 'max:10'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['code'] = strtoupper(trim($data['code']));
        $data['currency'] = strtoupper(trim($data['currency']));
        $data['is_default'] = $request->boolean('is_default');
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }

    private function unsetOtherDefaults(bool $isDefault, ?int $exceptId = null): void
    {
        if (! $isDefault) {
            return;
        }

        PriceList::query()->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))->update(['is_default' => false]);
    }

    private function authorizeManagement(): void
    {
        $user = auth()->user();
        abort_unless($user && ($user->user_type === 'admin' || $user->can('price_lists.manage')), 403);
    }
}
