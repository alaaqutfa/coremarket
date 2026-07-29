<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\CoreMarketCreditPaymentService;
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

    public function index(WebPosService $pos, CoreMarketCreditPaymentService $creditPayments): View
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
            'payOnAccountEnabled' => $creditPayments->canUsePos(auth()->user()),
        ]);
    }

    public function search(Request $request, WebPosService $pos): JsonResponse
    {
        $this->authorizePos(['pos.view']);

        $data = $request->validate([
            'q' => 'required|string|max:255',
            'customer_id' => 'nullable|integer|min:1',
        ]);
        $customer = $pos->validatePosCustomer(isset($data['customer_id']) ? (int) $data['customer_id'] : null);

        return response()->json($pos->searchProducts($data['q'], $customer, auth()->user())->values());
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

    public function customerCreditPreview(Request $request, WebPosService $pos): JsonResponse
    {
        $this->authorizePos(['pos.sell']);

        $data = $request->validate([
            'customer_id' => 'required|integer|min:1',
            'amount' => 'required|numeric|min:0',
        ]);
        $customer = $pos->validatePosCustomer((int) $data['customer_id']);

        return response()->json(
            $pos->creditDecisionForCustomer($customer, $data['amount'], auth()->user())
        );
    }

    public function checkout(Request $request, WebPosService $pos): RedirectResponse
    {
        $this->authorizePos(['pos.sell']);

        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|integer',
            'items.*.product_stock_id' => 'required|integer|exists:product_stocks,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.serial_unit_ids' => 'nullable|array',
            'items.*.serial_unit_ids.*' => 'integer|exists:product_serial_units,id',
            'payment_type' => 'nullable|in:cash,pay_on_account',
            'paid_amount' => 'nullable|required_unless:payment_type,pay_on_account|numeric|min:0',
            'pos_request_key' => 'required|string|max:255',
            'customer_id' => 'nullable|integer|min:1',
            'points_to_redeem' => 'nullable|integer|min:0',
        ]);

        if ((int) ($data['points_to_redeem'] ?? 0) > 0) {
            $this->authorizeLoyaltyRedemption();
        }

        try {
            $order = $pos->createPosOrder(
                $data['items'],
                [
                    'payment_type' => $data['payment_type'] ?? 'cash',
                    'paid_amount' => $data['paid_amount'] ?? 0,
                    'customer_id' => $data['customer_id'] ?? null,
                    'points_to_redeem' => $data['points_to_redeem'] ?? 0,
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

    private function authorizeLoyaltyRedemption(): void
    {
        $user = auth()->user();

        if (! $user || ! $user->can('pos.redeem_loyalty')) {
            abort(403);
        }
    }
}
