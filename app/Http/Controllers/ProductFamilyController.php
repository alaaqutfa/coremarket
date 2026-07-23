<?php

namespace App\Http\Controllers;

use App\Models\ProductFamily;
use App\Services\CoreMarketProductClassificationService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductFamilyController extends Controller
{
    public function __construct(private CoreMarketProductClassificationService $classification)
    {
    }

    public function index(): View
    {
        $this->authorizeManagement();

        return view('backend.operations.inventory.families.index', [
            'families' => $this->classification->families(false),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorizeManagement();

        return view('backend.operations.inventory.families.form', [
            'productFamily' => new ProductFamily([
                'level' => $request->string('level')->toString() === 'sub_family' ? 'sub_family' : 'family',
                'parent_id' => $request->integer('parent_id') ?: null,
                'is_active' => true,
            ]),
            'families' => $this->classification->families(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManagement();
        $data = $this->validatedData($request);
        ProductFamily::query()->create($data);
        flash(translate('Product family created successfully'))->success();

        return redirect()->route('operations.inventory.families.index');
    }

    public function edit(ProductFamily $productFamily): View
    {
        $this->authorizeManagement();

        return view('backend.operations.inventory.families.form', [
            'productFamily' => $productFamily,
            'families' => $this->classification->families(),
        ]);
    }

    public function update(Request $request, ProductFamily $productFamily): RedirectResponse
    {
        $this->authorizeManagement();
        $data = $this->validatedData($request, $productFamily);
        if ($data['level'] === 'sub_family' && $productFamily->children()->exists()) {
            return back()->withErrors(['level' => translate('A family with sub families cannot become a sub family.')])->withInput();
        }

        $productFamily->update($data);
        flash(translate('Product family updated successfully'))->success();

        return redirect()->route('operations.inventory.families.index');
    }

    public function toggle(ProductFamily $productFamily): RedirectResponse
    {
        $this->authorizeManagement();
        if (! $productFamily->is_active && $productFamily->level === 'sub_family') {
            try {
                $this->classification->validateFamilyHierarchy($productFamily->parent_id, null);
            } catch (DomainException $exception) {
                return back()->withErrors(['family' => $exception->getMessage()]);
            }
        }

        $productFamily->update(['is_active' => ! $productFamily->is_active]);
        flash(translate('Product family status updated successfully'))->success();

        return back();
    }

    private function validatedData(Request $request, ?ProductFamily $productFamily = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'code' => ['nullable', 'string', 'max:100', Rule::unique('product_families', 'code')->ignore($productFamily?->id)],
            'level' => ['required', Rule::in(['family', 'sub_family'])],
            'parent_id' => ['nullable', 'integer', 'exists:product_families,id'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['code'] = filled($data['code'] ?? null) ? strtoupper(trim($data['code'])) : null;
        $data['is_active'] = $request->boolean('is_active');
        if ($data['level'] === 'family') {
            $data['parent_id'] = null;
        } else {
            if (blank($data['parent_id'] ?? null) || (int) $data['parent_id'] === (int) $productFamily?->id) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'parent_id' => translate('A sub family requires a different active parent family.'),
                ]);
            }
            try {
                $this->classification->validateFamilyHierarchy($data['parent_id'], null);
            } catch (DomainException $exception) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'parent_id' => $exception->getMessage(),
                ]);
            }
        }

        return $data;
    }

    private function authorizeManagement(): void
    {
        $user = auth()->user();
        abort_unless($user && ($user->user_type === 'admin' || $user->can('inventory.families.manage')), 403);
    }
}
