<?php

namespace App\Services;

use App\Models\CashierShift;
use App\Models\CustomerLedgerEntry;
use App\Models\SalesReturn;
use App\Models\SalesReturnRefund;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class CoreMarketSalesReturnRefundService
{
    public function __construct(
        private CoreMarketCustomerReceivableService $receivables,
        private CashboxService $cashboxes,
        private CoreMarketMoneyService $money
    ) {
    }

    public function refundableAmount(SalesReturn $salesReturn): float
    {
        return max(0, $this->money->normalizeMoney($salesReturn->total_amount));
    }

    public function refundedAmount(SalesReturn $salesReturn): float
    {
        $postedRefunds = (float) SalesReturnRefund::query()
            ->where('sales_return_id', $salesReturn->id)
            ->where('status', 'posted')
            ->sum('amount');
        $linkedLedgerIds = SalesReturnRefund::query()
            ->where('sales_return_id', $salesReturn->id)
            ->whereNotNull('customer_ledger_entry_id')
            ->pluck('customer_ledger_entry_id');
        $legacyCredits = (float) CustomerLedgerEntry::query()
            ->where('sales_return_id', $salesReturn->id)
            ->where('entry_type', 'credit_note')
            ->where('direction', 'credit')
            ->when($linkedLedgerIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $linkedLedgerIds))
            ->sum('amount');

        return $this->money->normalizeMoney($postedRefunds + $legacyCredits);
    }

    public function remainingRefundableAmount(SalesReturn $salesReturn): float
    {
        return max(0, $this->money->normalizeMoney(
            $this->refundableAmount($salesReturn) - $this->refundedAmount($salesReturn)
        ));
    }

    public function refundToCash(
        SalesReturn $salesReturn,
        mixed $amount,
        User $actor,
        CashierShift $shift,
        string $idempotencyKey,
        ?string $notes = null
    ): SalesReturnRefund {
        return $this->post($salesReturn, $amount, 'cash', $actor, $idempotencyKey, $shift, $notes);
    }

    public function creditCustomerAccount(
        SalesReturn $salesReturn,
        mixed $amount,
        User $actor,
        string $idempotencyKey,
        ?string $notes = null
    ): SalesReturnRefund {
        return $this->post($salesReturn, $amount, 'customer_account_credit', $actor, $idempotencyKey, null, $notes);
    }

    public function refundSnapshot(SalesReturn $salesReturn): array
    {
        $refundable = $this->refundableAmount($salesReturn);
        $refunded = $this->refundedAmount($salesReturn);

        return [
            'refundable_amount' => $refundable,
            'refunded_amount' => $refunded,
            'remaining_amount' => max(0, $this->money->normalizeMoney($refundable - $refunded)),
            'preferred_method' => $salesReturn->order?->payment_type === 'pay_on_account'
                ? 'customer_account_credit'
                : 'cash',
        ];
    }

    private function post(
        SalesReturn $salesReturn,
        mixed $amount,
        string $method,
        User $actor,
        string $idempotencyKey,
        ?CashierShift $shift,
        ?string $notes
    ): SalesReturnRefund {
        $this->assertAuthorized($actor, $method);
        $amount = $this->positiveAmount($amount);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 120) {
            throw new DomainException('Refund idempotency key is invalid.');
        }

        return DB::transaction(function () use ($salesReturn, $amount, $method, $actor, $idempotencyKey, $shift, $notes) {
            $existing = SalesReturnRefund::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                if ((int) $existing->sales_return_id !== (int) $salesReturn->id || $existing->refund_method !== $method) {
                    throw new DomainException('Refund idempotency key was already used for another refund.');
                }

                return $existing;
            }

            $lockedReturn = SalesReturn::query()
                ->with('order.user')
                ->lockForUpdate()
                ->findOrFail($salesReturn->id);
            if ($lockedReturn->status !== 'completed') {
                throw new DomainException('Only a completed sales return can be refunded.');
            }
            if ($amount > $this->remainingRefundableAmount($lockedReturn)) {
                throw new DomainException('Refund amount exceeds the remaining refundable amount.');
            }

            $lockedShift = $method === 'cash'
                ? $this->validatedCashShift($shift, $actor)
                : null;
            if ($method === 'customer_account_credit' && ! $lockedReturn->order?->user_id) {
                throw new DomainException('Customer account credit requires an identified customer.');
            }

            $refund = SalesReturnRefund::query()->create([
                'sales_return_id' => $lockedReturn->id,
                'order_id' => $lockedReturn->order_id,
                'customer_id' => $lockedReturn->order?->user_id,
                'refunded_by_user_id' => $actor->id,
                'cashbox_id' => $lockedShift?->cashbox_id,
                'cashier_shift_id' => $lockedShift?->id,
                'refund_method' => $method,
                'amount' => $amount,
                'currency' => $this->money->baseCurrency(),
                'status' => 'draft',
                'notes' => $notes,
                'idempotency_key' => $idempotencyKey,
                'metadata' => [
                    'original_payment_type' => $lockedReturn->order?->payment_type,
                    'order_payment_status_unchanged' => true,
                ],
            ]);

            if ($method === 'cash') {
                $movement = $this->cashboxes->recordSalesReturnRefundMovement($refund, $lockedShift, $actor);
                $refund->cash_movement_id = $movement->id;
            } else {
                $entry = $this->receivables->createCreditNoteFromSalesReturn(
                    $lockedReturn,
                    $actor,
                    $amount,
                    'sales-return-refund:'.$refund->id.':credit-note',
                    ['sales_return_refund_id' => $refund->id]
                );
                $refund->customer_ledger_entry_id = $entry->id;
            }

            $refund->status = 'posted';
            $refund->save();

            return $refund->fresh(['cashMovement', 'customerLedgerEntry']);
        });
    }

    private function validatedCashShift(?CashierShift $shift, User $actor): CashierShift
    {
        if (! $shift) {
            throw new DomainException('Cash refunds require an open cashbox shift.');
        }
        $shift = CashierShift::query()->with('cashbox')->lockForUpdate()->findOrFail($shift->id);
        if (! $shift->isOpen() || ! $shift->cashbox?->isActive()) {
            throw new DomainException('Cash refunds require an active cashbox with an open shift.');
        }
        if (
            $actor->user_type !== 'admin'
            && $actor->hasRole('cashier')
            && ! $actor->hasAnyRole(['owner_general_manager', 'store_admin', 'accountant'])
            && (int) $shift->opened_by !== (int) $actor->id
        ) {
            throw new DomainException('Cashiers can refund only from their own open shift.');
        }

        return $shift;
    }

    private function assertAuthorized(User $actor, string $method): void
    {
        $permission = $method === 'cash'
            ? 'sales_returns.refunds.cash'
            : 'sales_returns.refunds.credit_account';
        if ($actor->user_type !== 'admin' && ! $actor->can($permission)) {
            throw new DomainException('You are not authorized to post this sales return refund.');
        }
    }

    private function positiveAmount(mixed $amount): float
    {
        if (! is_numeric($amount)) {
            throw new DomainException('Refund amount must be greater than zero.');
        }
        $amount = $this->money->normalizeMoney($amount);
        if ($amount <= 0) {
            throw new DomainException('Refund amount must be greater than zero.');
        }

        return $amount;
    }
}
