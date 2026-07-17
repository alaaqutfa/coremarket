<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\CoreMarketFeatureAccessService;
use App\Services\WebPosService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WebPosController extends Controller
{
    public function __construct(private CoreMarketFeatureAccessService $features)
    {
    }

    public function index(WebPosService $pos): View
    {
        $this->authorizePos(['pos.view']);

        try {
            $openShift = $pos->requireOpenShift(auth()->user());
        } catch (DomainException) {
            $openShift = null;
        }

        return view('backend.operations.pos.index', [
            'openShift' => $openShift?->load('cashbox'),
            'canSell' => $this->canAny(['pos.sell']),
            'canOpenShift' => $this->canAny(['cash_shifts.open']),
        ]);
    }

    public function search(Request $request, WebPosService $pos): JsonResponse
    {
        $this->authorizePos(['pos.view']);

        $data = $request->validate([
            'q' => 'required|string|max:255',
        ]);

        return response()->json($pos->searchProducts($data['q'])->values());
    }

    public function checkout(Request $request, WebPosService $pos): RedirectResponse
    {
        $this->authorizePos(['pos.sell']);

        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|integer',
            'items.*.product_stock_id' => 'required|integer|exists:product_stocks,id',
            'items.*.quantity' => 'required|integer|min:1',
            'paid_amount' => 'required|numeric|min:0',
            'pos_request_key' => 'required|string|max:255',
        ]);

        try {
            $order = $pos->createPosOrder(
                $data['items'],
                ['payment_type' => 'cash', 'paid_amount' => $data['paid_amount']],
                auth()->user(),
                $data['pos_request_key']
            );
        } catch (DomainException $exception) {
            return back()->withErrors(['pos' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('operations.pos.receipt', $order)
            ->with('success', translate('POS sale completed successfully'));
    }

    public function receipt(Order $order): View
    {
        $this->authorizePos(['pos.receipts.view']);

        if (! $order->isPosOrder()) {
            abort(404);
        }

        $order->load(['orderDetails.product', 'cashier', 'cashbox', 'cashierShift']);

        return view('backend.operations.pos.receipt', compact('order'));
    }

    private function authorizePos(array $permissions): void
    {
        // Features are always enforced, including for the owner/admin role.
        if (! $this->features->enabled('pos') || ! $this->features->enabled('cashbox_shifts')) {
            abort(404);
        }

        if (! $this->canAny($permissions)) {
            abort(403);
        }
    }

    private function canAny(array $permissions): bool
    {
        $user = auth()->user();

        return $user && ($user->user_type === 'admin' || $user->hasAnyPermission($permissions));
    }
}
