<?php

namespace App\Services;

use App\Models\CashierShift;
use App\Models\DeliveryCodSettlement;
use App\Models\OrderDelivery;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class CoreMarketCodSettlementService
{
    public function __construct(
        private CashboxService $cashboxes,
        private CoreMarketMoneyService $money
    ) {
    }

    public function collectibleAmount(OrderDelivery $delivery): float
    {
        return $this->money->normalizeMoney($delivery->cod_collected_amount ?? 0);
    }

    public function settledAmount(OrderDelivery $delivery): float
    {
        return $this->money->normalizeMoney(
            $delivery->settlements()->where('status', 'posted')->sum('amount')
        );
    }

    public function remainingAmount(OrderDelivery $delivery): float
    {
        return max(0, $this->money->normalizeMoney(
            $this->collectibleAmount($delivery) - $this->settledAmount($delivery)
        ));
    }

    public function canSettle(User $user, OrderDelivery $delivery): bool
    {
        if ($user->user_type === 'admin') {
            return true;
        }

        if (! $user->can('deliveries.settle_cod')) {
            return false;
        }

        return ! $user->hasRole('delivery_distribution')
            || $user->hasAnyRole(['owner_general_manager', 'store_admin', 'accountant', 'cashier']);
    }

    public function availableOpenShifts(User $receiver): Collection
    {
        return CashierShift::query()
            ->with('cashbox')
            ->where('status', 'open')
            ->when(
                $this->isCashierOnly($receiver),
                fn ($query) => $query->where('opened_by', $receiver->id)
            )
            ->latest('opened_at')
            ->get();
    }

    public function settle(
        OrderDelivery $delivery,
        mixed $amount,
        User $receiver,
        CashierShift $shift,
        string $idempotencyKey,
        ?string $notes = null
    ): DeliveryCodSettlement {
        if (! $this->canSettle($receiver, $delivery)) {
            throw new DomainException('Your account cannot settle COD funds.');
        }

        $normalizedAmount = $this->money->normalizeMoney($amount);
        if ($normalizedAmount <= 0) {
            throw new DomainException('Settlement amount must be greater than zero.');
        }

        if (blank($idempotencyKey) || strlen($idempotencyKey) > 64) {
            throw new DomainException('Settlement request key is invalid.');
        }

        return DB::transaction(function () use (
            $delivery,
            $normalizedAmount,
            $receiver,
            $shift,
            $idempotencyKey,
            $notes
        ) {
            $existing = DeliveryCodSettlement::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                if (
                    (int) $existing->order_delivery_id !== (int) $delivery->id
                    || abs((float) $existing->amount - $normalizedAmount) > 0.000001
                    || (int) $existing->cashier_shift_id !== (int) $shift->id
                ) {
                    throw new DomainException('Settlement request key was already used with different data.');
                }

                return $existing;
            }

            $lockedDelivery = OrderDelivery::query()->lockForUpdate()->findOrFail($delivery->id);
            $lockedShift = CashierShift::query()->with('cashbox')->lockForUpdate()->findOrFail($shift->id);

            if (! in_array($lockedDelivery->cod_collection_status, ['partially_collected', 'collected'], true)) {
                throw new DomainException('Only collected COD funds can be settled.');
            }
            if (! $lockedShift->isOpen()) {
                throw new DomainException('No open cashbox shift available.');
            }
            if ($this->isCashierOnly($receiver) && (int) $lockedShift->opened_by !== (int) $receiver->id) {
                throw new DomainException('Cashiers can settle COD only into their own open shift.');
            }

            $remaining = $this->remainingAmount($lockedDelivery);
            if ($normalizedAmount - $remaining > 0.000001) {
                throw new DomainException('Settlement amount cannot exceed the collected COD remaining balance.');
            }

            $settlement = DeliveryCodSettlement::query()->create([
                'order_delivery_id' => $lockedDelivery->id,
                'order_id' => $lockedDelivery->order_id,
                'delivery_user_id' => $lockedDelivery->delivery_user_id,
                'received_by_user_id' => $receiver->id,
                'cashbox_id' => $lockedShift->cashbox_id,
                'cashier_shift_id' => $lockedShift->id,
                'amount' => $normalizedAmount,
                'currency' => $lockedShift->cashbox?->currency ?: 'USD',
                'status' => 'posted',
                'idempotency_key' => $idempotencyKey,
                'notes' => $notes,
                'metadata' => [
                    'source' => 'delivery_cod_settlement',
                    'order_payment_status_unchanged' => true,
                ],
            ]);

            $movement = $this->cashboxes->recordDeliveryCodSettlementMovement(
                $settlement,
                $lockedDelivery,
                $lockedShift,
                $receiver
            );
            $settlement->forceFill(['cash_movement_id' => $movement->id])->save();

            $lockedDelivery->events()->create([
                'user_id' => $receiver->id,
                'old_status' => $lockedDelivery->status,
                'new_status' => $lockedDelivery->status,
                'notes' => 'COD funds settled into cashbox.',
                'metadata' => [
                    'settlement_id' => $settlement->id,
                    'settled_amount' => $normalizedAmount,
                    'cashbox_id' => $lockedShift->cashbox_id,
                    'cashier_shift_id' => $lockedShift->id,
                ],
                'created_at' => now(),
            ]);

            return $settlement->fresh(['cashbox', 'shift', 'cashMovement', 'receiver']);
        });
    }

    public function settlementSnapshot(OrderDelivery $delivery): array
    {
        $collected = $this->collectibleAmount($delivery);
        $settled = $this->settledAmount($delivery);
        $remaining = max(0, $this->money->normalizeMoney($collected - $settled));

        return [
            'collected_amount' => $collected,
            'settled_amount' => $settled,
            'remaining_amount' => $remaining,
            'status' => $remaining <= 0 && $collected > 0
                ? 'settled'
                : ($settled > 0 ? 'partially_settled' : 'pending_settlement'),
        ];
    }

    private function isCashierOnly(User $user): bool
    {
        return $user->user_type !== 'admin'
            && $user->hasRole('cashier')
            && ! $user->hasAnyRole(['owner_general_manager', 'store_admin', 'accountant']);
    }
}
