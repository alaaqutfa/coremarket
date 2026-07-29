<?php

namespace App\Http\Controllers;

use App\Models\StoreBranch;
use App\Models\User;
use App\Services\CoreMarketBranchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StoreBranchController extends Controller
{
    public function __construct(private CoreMarketBranchService $branches)
    {
        $this->middleware(['permission:branches.manage']);
    }

    public function index(): View
    {
        $this->branches->ensureDefaultBranch();

        return view('backend.operations.branches.index', [
            'branches' => StoreBranch::query()->with('manager')->orderByDesc('is_default')->orderBy('name')->get(),
            'managers' => User::query()->where('user_type', 'staff')->orderBy('name')->get(['id', 'name', 'email']),
            'settings' => [
                'enabled' => $this->branches->branchesEnabled(),
                'price_policy' => $this->branches->pricePolicy(),
                'inventory_policy' => $this->branches->inventoryPolicy(),
                'price_lists_enabled' => app(\App\Services\CoreMarketPricingFeatureService::class)->priceListsEnabled(),
                'flexible_selling_price_enabled' => app(\App\Services\CoreMarketPricingFeatureService::class)->flexibleSellingPriceEnabled(),
                'branch_pricing_enabled' => app(\App\Services\CoreMarketBranchPricingService::class)->branchPricingEnabled(),
                'branch_pricing_priority' => app(\App\Services\CoreMarketBranchPricingService::class)->priority(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedBranch($request);
        $branch = StoreBranch::query()->create($data);
        if ($request->boolean('is_default')) {
            $this->branches->setDefault($branch);
        }
        flash(translate('Branch created successfully'))->success();

        return back();
    }

    public function update(Request $request, StoreBranch $storeBranch): RedirectResponse
    {
        $data = $this->validatedBranch($request, $storeBranch);
        if ($storeBranch->is_default && ! $data['is_active']) {
            return back()->withErrors(['is_active' => translate('The default branch must remain active.')]);
        }
        $storeBranch->update($data);
        if ($request->boolean('is_default')) {
            $this->branches->setDefault($storeBranch);
        }
        flash(translate('Branch updated successfully'))->success();

        return back();
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'branches_enabled' => ['nullable', 'boolean'],
            'price_policy' => ['required', Rule::in(['unified', 'branch_specific', 'branch_specific_future'])],
            'inventory_policy' => ['required', Rule::in(['unified', 'branch_specific_future'])],
            'price_lists_enabled' => ['nullable', 'boolean'],
            'flexible_selling_price_enabled' => ['nullable', 'boolean'],
            'branch_pricing_enabled' => ['nullable', 'boolean'],
            'branch_pricing_priority' => ['required', Rule::in(\App\Services\CoreMarketBranchPricingService::PRIORITIES)],
        ]);

        $settings = [
            'branches.enabled' => $request->boolean('branches_enabled') ? '1' : '0',
            'branches.price_policy' => $data['price_policy'],
            'branches.inventory_policy' => $data['inventory_policy'],
            'pricing.price_lists_enabled' => $request->boolean('price_lists_enabled') ? '1' : '0',
            'pricing.flexible_selling_price_enabled' => $request->boolean('flexible_selling_price_enabled') ? '1' : '0',
            'pricing.branch_pricing_enabled' => $request->boolean('branch_pricing_enabled') ? '1' : '0',
            'pricing.branch_pricing_priority' => $data['branch_pricing_priority'],
        ];
        DB::transaction(function () use ($settings) {
            foreach ($settings as $type => $value) {
                DB::table('business_settings')->updateOrInsert(
                    ['type' => $type, 'lang' => null],
                    ['value' => $value, 'updated_at' => now()]
                );
            }
        });
        Cache::forget('business_settings');
        flash(translate('Branch and pricing policies updated successfully'))->success();

        return back();
    }

    private function validatedBranch(Request $request, ?StoreBranch $branch = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'code' => ['nullable', 'string', 'max:100', Rule::unique('store_branches', 'code')->ignore($branch?->id)],
            'address' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:100'],
            'manager_user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('user_type', 'staff')),
            ],
            'is_active' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
        ]);
        $data['code'] = filled($data['code'] ?? null) ? strtoupper(trim($data['code'])) : null;
        $data['is_active'] = $request->boolean('is_active');
        unset($data['is_default']);

        return $data;
    }
}
