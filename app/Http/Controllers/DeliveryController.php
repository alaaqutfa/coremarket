<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\StoreBranch;
use App\Services\CoreMarketBranchService;
use App\Services\CoreMarketCodSettlementService;
use App\Services\CoreMarketDeliveryService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DeliveryController extends Controller
{
    public function __construct(
        private CoreMarketDeliveryService $deliveries,
        private CoreMarketCodSettlementService $codSettlements,
        private CoreMarketBranchService $branches
    ) {
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $this->authorizeListing($user);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(CoreMarketDeliveryService::STATUSES)],
            'delivery_user_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'cod_status' => ['nullable', Rule::in(['not_required', 'pending', 'collected', 'partially_collected', 'failed'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        $query = OrderDelivery::query()->with(['order.user', 'deliveryUser', 'branch'])->latest('updated_at');
        if (
            $user->user_type !== 'admin'
            && $user->can('deliveries.view_cod_summary')
            && ! $user->can('deliveries.view')
            && ! $user->can('deliveries.view_all')
            && ! $user->can('deliveries.view_assigned')
        ) {
            $query->whereIn('cod_collection_status', ['partially_collected', 'collected']);
        } elseif ($user->user_type !== 'admin' && ! $user->can('deliveries.view_all') && ! $user->can('deliveries.view')) {
            $query->where('delivery_user_id', $user->id);
        }
        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }
        if (filled($filters['delivery_user_id'] ?? null) && ($user->user_type === 'admin' || $user->can('deliveries.view_all'))) {
            $query->where('delivery_user_id', (int) $filters['delivery_user_id']);
        }
        if (filled($filters['branch_id'] ?? null)) {
            $query->where('branch_id', (int) $filters['branch_id']);
        }
        if (filled($filters['cod_status'] ?? null)) {
            $query->where('cod_collection_status', $filters['cod_status']);
        }
        if (filled($filters['date_from'] ?? null)) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (filled($filters['date_to'] ?? null)) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        $records = $query->paginate(25)->withQueryString();
        $records->getCollection()->transform(function (OrderDelivery $delivery) {
            $delivery->setAttribute('safe_snapshot', $this->deliveries->deliverySnapshot($delivery));

            return $delivery;
        });

        return view('backend.operations.deliveries.index', [
            'deliveries' => $records,
            'statuses' => CoreMarketDeliveryService::STATUSES,
            'branches' => $this->branches->activeBranches(),
            'deliveryUsers' => $user->user_type === 'admin' || $user->can('deliveries.view_all')
                ? $this->deliveries->availableDeliveryUsers()
                : collect(),
            'canEnsure' => $user->user_type === 'admin' || $user->can('deliveries.assign'),
        ]);
    }

    public function show(Request $request, OrderDelivery $orderDelivery): View
    {
        $this->authorizeDeliveryAccess($request, $orderDelivery);
        $orderDelivery->load(['order.user', 'deliveryUser', 'branch', 'events.user']);
        $user = $request->user();
        $canAssign = $user->user_type === 'admin' || $user->can('deliveries.assign');
        $canViewCodSummary = $user->user_type === 'admin' || $user->can('deliveries.view_cod_summary');
        $canSettleCod = $this->codSettlements->canSettle($user, $orderDelivery);

        $nextStatuses = $this->deliveries->allowedNextStatuses($orderDelivery);
        if ($user->user_type !== 'admin' && $user->can('deliveries.view_assigned') && ! $user->can('deliveries.view_all')) {
            $nextStatuses = array_values(array_intersect($nextStatuses, [
                'picked_up', 'out_for_delivery', 'delivered', 'failed',
            ]));
        }

        return view('backend.operations.deliveries.show', [
            'delivery' => $orderDelivery,
            'snapshot' => $this->deliveries->deliverySnapshot($orderDelivery),
            'nextStatuses' => $nextStatuses,
            'deliveryUsers' => $canAssign
                ? $this->deliveries->availableDeliveryUsers($orderDelivery->branch)
                : collect(),
            'canAssign' => $canAssign,
            'canUpdateStatus' => $user->user_type === 'admin' || $user->can('deliveries.update_status'),
            'canCollectCod' => $user->user_type === 'admin' || $user->can('deliveries.collect_cod'),
            'canViewCodSummary' => $canViewCodSummary,
            'canSettleCod' => $canSettleCod,
            'codSettlement' => $canViewCodSummary
                ? $this->codSettlements->settlementSnapshot($orderDelivery)
                : null,
            'openCashierShifts' => $canSettleCod
                ? $this->codSettlements->availableOpenShifts($user)
                : collect(),
            'settlementRequestKey' => (string) \Illuminate\Support\Str::uuid(),
        ]);
    }

    public function ensure(Request $request, Order $order): RedirectResponse
    {
        $this->authorizePermission($request, 'deliveries.assign');
        $delivery = $this->deliveries->ensureDeliveryForOrder($order);
        flash(translate('Delivery record is ready.'))->success();

        return redirect()->route('operations.deliveries.show', $delivery);
    }

    public function assign(Request $request, OrderDelivery $orderDelivery): RedirectResponse
    {
        $this->authorizePermission($request, 'deliveries.assign');
        $data = $request->validate([
            'delivery_user_id' => ['required', 'integer', Rule::exists('users', 'id')],
        ]);

        try {
            $this->deliveries->assignDeliveryUser(
                $orderDelivery->order,
                \App\Models\User::query()->findOrFail($data['delivery_user_id']),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()->withErrors(['delivery_user_id' => $exception->getMessage()]);
        }

        flash(translate('Delivery employee assigned successfully.'))->success();

        return back();
    }

    public function updateStatus(Request $request, OrderDelivery $orderDelivery): RedirectResponse
    {
        $this->authorizeDeliveryAccess($request, $orderDelivery);
        $this->authorizePermission($request, 'deliveries.update_status');
        $data = $request->validate([
            'status' => ['required', Rule::in(CoreMarketDeliveryService::STATUSES)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $user = $request->user();
        if (
            $user->user_type !== 'admin'
            && $user->can('deliveries.view_assigned')
            && ! $user->can('deliveries.view_all')
            && ! in_array($data['status'], ['picked_up', 'out_for_delivery', 'delivered', 'failed'], true)
        ) {
            abort(403);
        }

        try {
            $this->deliveries->updateStatus($orderDelivery, $data['status'], $data['notes'] ?? null, $user);
        } catch (DomainException $exception) {
            return back()->withErrors(['status' => $exception->getMessage()]);
        }

        flash(translate('Delivery status updated successfully.'))->success();

        return back();
    }

    public function collectCod(Request $request, OrderDelivery $orderDelivery): RedirectResponse
    {
        $this->authorizeDeliveryAccess($request, $orderDelivery);
        $this->authorizePermission($request, 'deliveries.collect_cod');
        $data = $request->validate([
            'cod_collected_amount' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            $this->deliveries->collectCod($orderDelivery, (float) $data['cod_collected_amount'], $request->user());
        } catch (DomainException $exception) {
            return back()->withErrors(['cod_collected_amount' => $exception->getMessage()]);
        }

        flash(translate('COD collection updated. Cashbox posting requires a separate settlement.'))->success();

        return back();
    }

    public function settleCod(Request $request, OrderDelivery $orderDelivery): RedirectResponse
    {
        $this->authorizeDeliveryAccess($request, $orderDelivery);
        $this->authorizePermission($request, 'deliveries.settle_cod');
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'cashier_shift_id' => ['required', 'integer', Rule::exists('cashier_shifts', 'id')],
            'settlement_request_key' => ['required', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->codSettlements->settle(
                $orderDelivery,
                $data['amount'],
                $request->user(),
                \App\Models\CashierShift::query()->findOrFail($data['cashier_shift_id']),
                $data['settlement_request_key'],
                $data['notes'] ?? null
            );
        } catch (DomainException $exception) {
            return back()->withErrors(['settlement' => $exception->getMessage()])->withInput();
        }

        flash(translate('COD funds received and posted to the open cashbox shift.'))->success();

        return back();
    }

    private function authorizeListing($user): void
    {
        if (! $user || (
            $user->user_type !== 'admin'
            && ! $user->can('deliveries.view')
            && ! $user->can('deliveries.view_all')
            && ! $user->can('deliveries.view_assigned')
            && ! $user->can('deliveries.view_cod_summary')
        )) {
            abort(403);
        }
    }

    private function authorizeDeliveryAccess(Request $request, OrderDelivery $delivery): void
    {
        if (! $this->deliveries->userCanAccessDelivery($request->user(), $delivery)) {
            abort(403);
        }
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        $user = $request->user();
        if (! $user || ($user->user_type !== 'admin' && ! $user->can($permission))) {
            abort(403);
        }
    }
}
