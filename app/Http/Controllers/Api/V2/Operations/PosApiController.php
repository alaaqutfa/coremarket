<?php

namespace App\Http\Controllers\Api\V2\Operations;

use App\Http\Controllers\Api\V2\Controller;
use App\Http\Controllers\Api\V2\Operations\Concerns\RespondsWithApiJson;
use App\Models\Order;
use App\Services\CoreMarketFeatureAccessService;
use App\Services\WebPosService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

class PosApiController extends Controller
{
    use RespondsWithApiJson;

    public function session(WebPosService $pos): JsonResponse
    {
        $this->authorizePos('pos.view');

        return $this->success($pos->currentSessionPayload(request()->user()));
    }

    public function search(Request $request, WebPosService $pos): JsonResponse
    {
        $this->authorizePos('pos.view');

        $validator = Validator::make($request->query(), [
            'q' => ['required', 'string', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        return $this->success([
            'items' => $pos->searchPayload($pos->searchProducts($validator->validated()['q'])),
        ]);
    }

    public function customersSearch(Request $request, WebPosService $pos, CoreMarketFeatureAccessService $features): JsonResponse
    {
        $this->authorizePos('pos.view');

        $validator = Validator::make($request->query(), [
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $loyaltyEnabled = $features->enabled('loyalty_points');
        $items = $pos->searchCustomers(
            $validator->validated()['q'],
            $validator->validated()['limit'] ?? 10,
        )->map(function (array $customer) use ($loyaltyEnabled) {
            return [
                'id' => $customer['id'],
                'name' => $customer['name'],
                'phone' => $customer['phone'],
                'masked_email' => $customer['masked_email'],
                'loyalty' => $loyaltyEnabled ? [
                    'enabled' => true,
                    'balance' => $customer['loyalty_balance'],
                ] : null,
            ];
        })->values()->all();

        return $this->success(['items' => $items]);
    }

    public function checkout(Request $request, WebPosService $pos): JsonResponse
    {
        $this->authorizePos('pos.sell');

        $validator = Validator::make($request->all(), [
            'pos_request_key' => ['required', 'string', 'max:191'],
            'paid_amount' => ['required', 'numeric', 'min:0'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'points_to_redeem' => ['nullable', 'integer', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.product_stock_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        if ((int) ($validator->validated()['points_to_redeem'] ?? 0) > 0) {
            $this->ensureRedemptionPermission($request);
        }

        try {
            $order = $pos->createPosOrder(
                $validator->validated()['items'],
                [
                    'payment_type' => 'cash',
                    'paid_amount' => $validator->validated()['paid_amount'],
                    'customer_id' => $validator->validated()['customer_id'] ?? null,
                    'points_to_redeem' => $validator->validated()['points_to_redeem'] ?? 0,
                ],
                $request->user(),
                $validator->validated()['pos_request_key'],
            );
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error('Unable to complete the POS order.', [], 500);
        }

        return $this->success(
            array_merge($pos->checkoutSummaryPayload($order), [
                'receipt_url' => route('api.v2.operations.pos.receipt', $order),
            ]),
            'POS order created',
            201,
        );
    }

    public function receipt(Order $order, WebPosService $pos): JsonResponse
    {
        $this->authorizePos('pos.receipts.view');

        if (! $order->isPosOrder() || ! $order->hasPosReceipt()) {
            abort(404);
        }

        $user = request()->user();
        if ($user->user_type !== 'admin' && (int) $order->cashier_id !== (int) $user->id) {
            abort(403);
        }

        return $this->success($pos->receiptPayload($order));
    }

    private function authorizePos(string $permission): void
    {
        $this->ensureFeaturesEnabled();
        $this->ensurePermission($permission);
    }

    private function ensureRedemptionPermission(Request $request): void
    {
        if (! $request->user()?->can('pos.redeem_loyalty')) {
            abort(403);
        }
    }

    private function domainError(DomainException $exception): JsonResponse
    {
        $message = $exception->getMessage();
        $isConflict = str_contains($message, 'open cashier shift')
            || str_contains($message, 'exceeds available stock')
            || str_contains($message, 'unavailable');

        return $isConflict
            ? $this->conflict($message)
            : $this->error($message, [], 422);
    }
}
