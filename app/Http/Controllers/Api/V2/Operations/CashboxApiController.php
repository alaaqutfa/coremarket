<?php

namespace App\Http\Controllers\Api\V2\Operations;

use App\Http\Controllers\Api\V2\Controller;
use App\Http\Controllers\Api\V2\Operations\Concerns\RespondsWithApiJson;
use App\Models\Cashbox;
use App\Models\CashierShift;
use App\Services\CashboxService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CashboxApiController extends Controller
{
    use RespondsWithApiJson;

    public function cashboxes(CashboxService $cashboxes): JsonResponse
    {
        $this->authorizeCashbox('cashboxes.view');

        $user = request()->user();
        $query = Cashbox::query()->where('status', 'active')->orderBy('name');

        if ($user->user_type !== 'admin') {
            $query->where(function ($query) use ($user) {
                $query->whereNull('assigned_user_id')->orWhere('assigned_user_id', $user->id);
            });
        }

        $cashboxes = $query->get();
        $openShifts = CashierShift::query()
            ->where('status', 'open')
            ->whereIn('cashbox_id', $cashboxes->pluck('id'))
            ->get()
            ->keyBy('cashbox_id');

        return $this->success([
            'cashboxes' => $cashboxes
                ->map(fn (Cashbox $cashbox) => $this->cashboxPayload($cashbox, $openShifts->get($cashbox->id)))
                ->values()
                ->all(),
        ]);
    }

    public function currentShift(CashboxService $cashboxes): JsonResponse
    {
        $this->authorizeCashboxAny(['pos.view', 'cash_shifts.view']);

        return $this->success($this->currentShiftPayload($cashboxes->currentOpenShiftForUser(request()->user()), $cashboxes));
    }

    public function openShift(Request $request, Cashbox $cashbox, CashboxService $cashboxes): JsonResponse
    {
        $this->authorizeCashbox('cash_shifts.open');
        $this->ensureCashboxIsAvailableToUser($cashbox, $request->user());

        $validator = Validator::make($request->all(), [
            'opening_cash' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        try {
            $shift = $cashboxes->openShift(
                $cashbox,
                $request->user(),
                $validator->validated()['opening_cash'],
                $validator->validated()['note'] ?? null,
            )->load('cashbox');
        } catch (DomainException $exception) {
            return $this->conflict($exception->getMessage());
        }

        return $this->success([
            'shift' => $this->shiftPayload($shift, $cashboxes),
            'cashbox' => $this->cashboxPayload($shift->cashbox, $shift),
            'expected_cash' => $cashboxes->calculateExpectedCash($shift),
        ], 'Shift opened', 201);
    }

    public function closeShift(Request $request, CashierShift $shift, CashboxService $cashboxes): JsonResponse
    {
        $this->authorizeCashbox('cash_shifts.close');
        $this->ensureShiftBelongsToUser($shift, $request->user());

        $validator = Validator::make($request->all(), [
            'actual_cash' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        try {
            $shift = $cashboxes->closeShift(
                $shift,
                $validator->validated()['actual_cash'],
                $validator->validated()['note'] ?? null,
                $request->user(),
            )->load('cashbox');
        } catch (DomainException $exception) {
            return $this->conflict($exception->getMessage());
        }

        return $this->success([
            'shift' => $this->shiftPayload($shift, $cashboxes),
            'cashbox' => $this->cashboxPayload($shift->cashbox),
            'expected_cash' => (float) $shift->expected_cash,
            'actual_cash' => (float) $shift->actual_cash,
            'cash_difference' => (float) $shift->cash_difference,
        ], 'Shift closed');
    }

    private function authorizeCashbox(string $permission): void
    {
        $this->ensureFeaturesEnabled();
        $this->ensurePermission($permission);
    }

    private function authorizeCashboxAny(array $permissions): void
    {
        $this->ensureFeaturesEnabled();
        $this->ensureAnyPermission($permissions);
    }

    private function ensureCashboxIsAvailableToUser(Cashbox $cashbox, $user): void
    {
        if ($user->user_type !== 'admin'
            && $cashbox->assigned_user_id !== null
            && (int) $cashbox->assigned_user_id !== (int) $user->id) {
            abort(403);
        }
    }

    private function ensureShiftBelongsToUser(CashierShift $shift, $user): void
    {
        if ($user->user_type !== 'admin' && (int) $shift->opened_by !== (int) $user->id) {
            abort(403);
        }
    }

    private function currentShiftPayload(?CashierShift $shift, CashboxService $cashboxes): array
    {
        if (! $shift) {
            return [
                'has_open_shift' => false,
                'shift' => null,
                'cashbox' => null,
                'expected_cash' => null,
            ];
        }

        return [
            'has_open_shift' => true,
            'shift' => $this->shiftPayload($shift, $cashboxes),
            'cashbox' => $this->cashboxPayload($shift->cashbox, $shift),
            'expected_cash' => $cashboxes->calculateExpectedCash($shift),
        ];
    }

    private function cashboxPayload(Cashbox $cashbox, ?CashierShift $openShift = null): array
    {
        return [
            'id' => $cashbox->id,
            'name' => $cashbox->name,
            'code' => $cashbox->code,
            'status' => $cashbox->status,
            'currency' => $cashbox->currency,
            'assigned_user_id' => $cashbox->assigned_user_id,
            'has_open_shift' => $openShift !== null,
            'open_shift_id' => $openShift?->id,
        ];
    }

    private function shiftPayload(CashierShift $shift, CashboxService $cashboxes): array
    {
        return [
            'id' => $shift->id,
            'status' => $shift->status,
            'opened_at' => $shift->opened_at?->toIso8601String(),
            'closed_at' => $shift->closed_at?->toIso8601String(),
            'opening_balance' => (float) $shift->opening_balance,
            'expected_cash' => $shift->isOpen()
                ? $cashboxes->calculateExpectedCash($shift)
                : (float) $shift->expected_cash,
            'actual_cash' => $shift->actual_cash === null ? null : (float) $shift->actual_cash,
            'cash_difference' => $shift->cash_difference === null ? null : (float) $shift->cash_difference,
        ];
    }
}
