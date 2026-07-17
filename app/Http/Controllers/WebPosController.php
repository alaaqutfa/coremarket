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
            'loyaltyEnabled' => $this->features->enabled('loyalty_points'),
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

    public function customersSearch(Request $request, WebPosService $pos): JsonResponse
    {
        $this->authorizePos(['pos.view']);

        $data = $request->validate([
            'q' => 'required|string|min:2|max:100',
            'limit' => 'nullable|integer|min:1|max:10',
        ]);
        $loyaltyEnabled = $this->features->enabled('loyalty_points');

        return response()->json([
            'items' => $pos->searchCustomers($data['q'], $data['limit'] ?? 10)
                ->map(fn (array $customer) => [
                    'id' => $customer['id'],
                    'name' => $customer['name'],
                    'phone' => $customer['phone'],
                    'masked_email' => $customer['masked_email'],
                    'loyalty' => $loyaltyEnabled ? [
                        'enabled' => true,
                        'balance' => $customer['loyalty_balance'],
                    ] : null,
                ])->values(),
        ]);
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
            'customer_id' => 'nullable|integer|min:1',
        ]);

        try {
            $order = $pos->createPosOrder(
                $data['items'],
                [
                    'payment_type' => 'cash',
                    'paid_amount' => $data['paid_amount'],
                    'customer_id' => $data['customer_id'] ?? null,
                ],
                auth()->user(),
                $data['pos_request_key']
            );
        } catch (DomainException $exception) {
            return back()->withErrors(['pos' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('operations.pos.receipt', $order)
            ->with('success', translate('POS sale completed successfully'));
    }

    public function receipt(Order $order, WebPosService $pos): View
    {
        $this->authorizePos(['pos.receipts.view']);

        if (! $order->isPosOrder()) {
            abort(404);
        }

        $order->load(['orderDetails.product', 'cashier', 'cashbox', 'cashierShift', 'user']);
        $receipt = $pos->receiptPayload($order);

        return view('backend.operations.pos.receipt', compact('order', 'receipt'));
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
