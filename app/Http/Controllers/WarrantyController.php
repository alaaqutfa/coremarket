<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductSerialUnit;
use App\Models\ProductStock;
use App\Models\ProductWarrantyPolicy;
use App\Models\WarrantyClaim;
use App\Services\CoreMarketSerialInventoryService;
use App\Services\CoreMarketWarrantyService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WarrantyController extends Controller
{
    public function index(Request $request, CoreMarketSerialInventoryService $serials): View
    {
        $this->authorizePermission('warranty.claims.view');
        $identity = trim((string) $request->query('identity'));

        return view('backend.operations.warranty.index', [
            'claims' => WarrantyClaim::query()
                ->with(['serialUnit', 'product', 'customer'])
                ->latest()
                ->paginate(25),
            'policies' => ProductWarrantyPolicy::query()
                ->with(['product', 'productStock'])
                ->latest()
                ->get(),
            'products' => Product::query()->orderBy('name')->limit(250)->get(['id', 'name']),
            'stocks' => ProductStock::query()->with('product:id,name')->orderBy('product_id')->limit(500)->get(),
            'serialUnit' => $identity !== '' ? $serials->findBySerialOrImei($identity) : null,
            'identity' => $identity,
        ]);
    }

    public function storePolicy(Request $request): RedirectResponse
    {
        $this->authorizePermission('warranty.policies.manage');
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'product_stock_id' => 'nullable|integer|exists:product_stocks,id',
            'name' => 'required|string|max:255',
            'warranty_months' => 'required|integer|min:0|max:240',
            'coverage_notes' => 'nullable|string|max:5000',
            'status' => 'required|in:active,inactive',
            'serial_tracking_enabled' => 'nullable|boolean',
            'imei_tracking_enabled' => 'nullable|boolean',
        ]);

        if (! empty($data['product_stock_id'])) {
            $stock = ProductStock::query()
                ->where('product_id', $data['product_id'])
                ->findOrFail($data['product_stock_id']);
            $stock->forceFill([
                'serial_tracking_enabled' => (bool) ($data['serial_tracking_enabled'] ?? false),
                'imei_tracking_enabled' => (bool) ($data['imei_tracking_enabled'] ?? false),
            ])->save();
        }

        ProductWarrantyPolicy::query()->create(collect($data)->except([
            'serial_tracking_enabled',
            'imei_tracking_enabled',
        ])->all());

        return back()->with('success', translate('Warranty policy created successfully'));
    }

    public function storeClaim(Request $request, CoreMarketWarrantyService $warranties): RedirectResponse
    {
        $this->authorizePermission('warranty.claims.create');
        $data = $request->validate([
            'product_serial_unit_id' => 'required|integer|exists:product_serial_units,id',
            'issue_description' => 'required|string|max:5000',
        ]);

        try {
            $claim = $warranties->createClaim($data, $request->user());
        } catch (DomainException $exception) {
            return back()->withErrors(['warranty' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('operations.warranty.claims.show', $claim)
            ->with('success', translate('Warranty claim created successfully'));
    }

    public function show(WarrantyClaim $warrantyClaim): View
    {
        $this->authorizePermission('warranty.claims.view');
        $warrantyClaim->load(['serialUnit.branch', 'product', 'productStock', 'customer', 'order', 'receivedBy', 'closedBy']);

        return view('backend.operations.warranty.show', compact('warrantyClaim'));
    }

    public function updateStatus(
        Request $request,
        WarrantyClaim $warrantyClaim,
        CoreMarketWarrantyService $warranties
    ): RedirectResponse {
        $this->authorizePermission('warranty.claims.manage');
        $data = $request->validate([
            'status' => 'required|in:'.implode(',', CoreMarketWarrantyService::STATUSES),
            'resolution_notes' => 'nullable|string|max:5000',
        ]);

        try {
            $warranties->updateClaimStatus(
                $warrantyClaim,
                $data['status'],
                $request->user(),
                $data['resolution_notes'] ?? null
            );
        } catch (DomainException $exception) {
            return back()->withErrors(['warranty' => $exception->getMessage()]);
        }

        return back()->with('success', translate('Warranty claim updated successfully'));
    }

    private function authorizePermission(string $permission): void
    {
        $user = auth()->user();
        abort_unless($user && ($user->user_type === 'admin' || $user->can($permission)), 403);
    }
}
