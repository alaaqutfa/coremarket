<?php

namespace App\Services;

use App\Models\CashierShift;
use App\Models\CustomerLedgerEntry;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentAllocation;
use App\Models\Order;
use App\Models\SalesReturn;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CoreMarketCustomerReceivableService
{
    public function __construct(
        private CoreMarketCustomerAccountFeatureService $features,
        private CoreMarketMoneyService $money,
        private CashboxService $cashboxes
    ) {
    }

    public function enabled(): bool
    {
        return $this->features->enabled()
            && Schema::hasTable('customer_ledger_entries')
            && Schema::hasTable('customer_payments')
            && Schema::hasTable('customer_payment_allocations');
    }

    public function customerBalance(User $customer): float
    {
        if (! Schema::hasTable('customer_ledger_entries')) {
            return 0.0;
        }

        return $this->money->normalizeMoney(
            CustomerLedgerEntry::query()
                ->where('customer_id', $customer->id)
                ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'debit' THEN amount ELSE -amount END), 0) balance")
                ->value('balance')
        );
    }

    public function customerLedger(User $customer, array $filters = []): Builder
    {
        return CustomerLedgerEntry::query()
            ->with(['order:id,code', 'payment:id,reference', 'salesReturn:id,return_number'])
            ->where('customer_id', $customer->id)
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->where('occurred_at', '>=', CarbonImmutable::parse($date)->startOfDay()))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->where('occurred_at', '<=', CarbonImmutable::parse($date)->endOfDay()))
            ->when($filters['entry_type'] ?? null, fn (Builder $query, string $type) => $query->where('entry_type', $type))
            ->orderBy('occurred_at')
            ->orderBy('id');
    }

    public function createInvoiceEntryFromOrder(Order $order, User $actor): CustomerLedgerEntry
    {
        $this->assertEnabled();
        if (! $order->user_id || ! $order->user || $order->user->user_type !== 'customer') {
            throw new DomainException('A customer account is required before posting this order.');
        }
        if ($order->payment_status === 'paid' || (float) ($order->paid_amount ?? 0) > 0) {
            throw new DomainException('Paid or partially paid orders cannot be posted without a matching AR payment record.');
        }

        return DB::transaction(function () use ($order, $actor) {
            $lockedOrder = Order::query()->with('user')->lockForUpdate()->findOrFail($order->id);
            $key = 'customer-invoice-order:'.$lockedOrder->id;
            $existing = CustomerLedgerEntry::query()->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }

            return CustomerLedgerEntry::query()->create([
                'customer_id' => $lockedOrder->user_id,
                'order_id' => $lockedOrder->id,
                'entry_type' => 'invoice',
                'direction' => 'debit',
                'amount' => $this->positiveAmount($lockedOrder->grand_total, 'Order total must be greater than zero.'),
                'currency' => $this->money->baseCurrency(),
                'exchange_rate' => 1,
                'occurred_at' => $lockedOrder->created_at ?: now(),
                'description' => 'Sales invoice '.($lockedOrder->code ?: '#'.$lockedOrder->id),
                'idempotency_key' => $key,
                'metadata' => [
                    'source' => 'manual_order_posting',
                    'order_payment_status_unchanged' => true,
                ],
                'created_by' => $actor->id,
            ]);
        });
    }

    public function createCreditNoteFromSalesReturn(SalesReturn $salesReturn, User $actor): CustomerLedgerEntry
    {
        $this->assertEnabled();
        $salesReturn->loadMissing('order.user');
        if ($salesReturn->status !== 'completed' || ! $salesReturn->order?->user_id) {
            throw new DomainException('Only a completed customer sales return can create a credit note.');
        }

        return DB::transaction(function () use ($salesReturn, $actor) {
            $lockedReturn = SalesReturn::query()->with('order.user')->lockForUpdate()->findOrFail($salesReturn->id);
            $key = 'customer-credit-sales-return:'.$lockedReturn->id;
            $existing = CustomerLedgerEntry::query()->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }

            return CustomerLedgerEntry::query()->create([
                'customer_id' => $lockedReturn->order->user_id,
                'order_id' => $lockedReturn->order_id,
                'sales_return_id' => $lockedReturn->id,
                'entry_type' => 'credit_note',
                'direction' => 'credit',
                'amount' => $this->positiveAmount($lockedReturn->total_amount, 'Sales return credit must be greater than zero.'),
                'currency' => $this->money->baseCurrency(),
                'exchange_rate' => 1,
                'occurred_at' => $lockedReturn->completed_at ?: now(),
                'description' => 'Sales return '.($lockedReturn->return_number ?: '#'.$lockedReturn->id),
                'idempotency_key' => $key,
                'metadata' => ['source' => 'manual_sales_return_credit'],
                'created_by' => $actor->id,
            ]);
        });
    }

    public function recordCustomerPayment(
        User $customer,
        mixed $amount,
        string $method,
        User $receiver,
        ?CashierShift $shift,
        string $idempotencyKey,
        array $allocations = [],
        ?string $reference = null,
        ?string $notes = null
    ): CustomerPayment {
        $this->assertEnabled();
        if ($customer->user_type !== 'customer') {
            throw new DomainException('Payments can be recorded only for customer accounts.');
        }
        if (! in_array($method, ['cash', 'bank_transfer', 'cheque', 'card_manual', 'other'], true)) {
            throw new DomainException('Unsupported customer payment method.');
        }
        if (blank($idempotencyKey) || strlen($idempotencyKey) > 100) {
            throw new DomainException('Payment request key is invalid.');
        }
        $amount = $this->positiveAmount($amount, 'Customer payment must be greater than zero.');

        return DB::transaction(function () use ($customer, $amount, $method, $receiver, $shift, $idempotencyKey, $allocations, $reference, $notes) {
            $existing = CustomerPayment::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing) {
                if (
                    (int) $existing->customer_id !== (int) $customer->id
                    || abs((float) $existing->amount - $amount) > 0.000001
                    || $existing->payment_method !== $method
                ) {
                    throw new DomainException('Payment request key was already used with different data.');
                }

                return $existing;
            }

            $lockedShift = $this->validatedShift($method, $shift, $receiver);
            $payment = CustomerPayment::query()->create([
                'customer_id' => $customer->id,
                'received_by_user_id' => $receiver->id,
                'cashbox_id' => $lockedShift?->cashbox_id,
                'cashier_shift_id' => $lockedShift?->id,
                'amount' => $amount,
                'currency' => $lockedShift?->cashbox?->currency ?: $this->money->baseCurrency(),
                'payment_method' => $method,
                'reference' => $reference,
                'status' => 'posted',
                'idempotency_key' => $idempotencyKey,
                'notes' => $notes,
                'metadata' => ['accounting_journal_pending' => true],
            ]);

            CustomerLedgerEntry::query()->create([
                'customer_id' => $customer->id,
                'customer_payment_id' => $payment->id,
                'entry_type' => 'payment',
                'direction' => 'credit',
                'amount' => $amount,
                'currency' => $payment->currency,
                'exchange_rate' => 1,
                'occurred_at' => now(),
                'description' => 'Customer payment'.($reference ? ' '.$reference : ' #'.$payment->id),
                'idempotency_key' => 'customer-payment-ledger:'.$payment->id,
                'metadata' => ['payment_method' => $method],
                'created_by' => $receiver->id,
            ]);

            $this->allocatePayment($payment, $allocations);

            if ($method === 'cash' && $lockedShift) {
                $movement = $this->cashboxes->recordCustomerPaymentMovement($payment, $lockedShift, $receiver);
                $payment->forceFill(['cash_movement_id' => $movement->id])->save();
            }

            return $payment->fresh(['ledgerEntries', 'allocations', 'cashMovement']);
        });
    }

    public function allocatePayment(CustomerPayment $payment, array $targets): Collection
    {
        $payment = CustomerPayment::query()->lockForUpdate()->findOrFail($payment->id);
        $allocated = $this->money->normalizeMoney($payment->allocations()->sum('amount'));
        $created = collect();

        foreach ($targets as $target) {
            $amount = $this->positiveAmount($target['amount'] ?? 0, 'Allocation amount must be greater than zero.');
            if ($allocated + $amount - (float) $payment->amount > 0.000001) {
                throw new DomainException('Payment allocations cannot exceed the payment amount.');
            }

            $entry = CustomerLedgerEntry::query()
                ->where('customer_id', $payment->customer_id)
                ->where('entry_type', 'invoice')
                ->lockForUpdate()
                ->findOrFail($target['customer_ledger_entry_id'] ?? 0);
            $outstanding = $this->outstandingAmountForEntry($entry);
            if ($amount - $outstanding > 0.000001) {
                throw new DomainException('Allocation cannot exceed the invoice outstanding amount.');
            }

            $allocation = CustomerPaymentAllocation::query()->create([
                'customer_payment_id' => $payment->id,
                'customer_ledger_entry_id' => $entry->id,
                'order_id' => $entry->order_id,
                'amount' => $amount,
            ]);
            $created->push($allocation);
            $allocated = $this->money->normalizeMoney($allocated + $amount);
        }

        return $created;
    }

    public function settledAmountForOrder(Order $order): float
    {
        return $this->money->normalizeMoney(
            CustomerPaymentAllocation::query()->where('order_id', $order->id)->sum('amount')
        );
    }

    public function outstandingAmountForOrder(Order $order): float
    {
        $invoice = CustomerLedgerEntry::query()
            ->where('order_id', $order->id)
            ->where('entry_type', 'invoice')
            ->first();

        return $invoice ? $this->outstandingAmountForEntry($invoice) : 0.0;
    }

    public function agingSummary(?User $customer = null): array
    {
        if (! Schema::hasTable('customer_ledger_entries')) {
            return $this->emptyAging();
        }

        $entries = CustomerLedgerEntry::query()
            ->with('allocations')
            ->where('entry_type', 'invoice')
            ->when($customer, fn (Builder $query) => $query->where('customer_id', $customer->id))
            ->get();
        $aging = $this->emptyAging();
        foreach ($entries as $entry) {
            $outstanding = $this->outstandingAmountForEntry($entry);
            if ($outstanding <= 0) {
                continue;
            }
            $days = CarbonImmutable::parse($entry->occurred_at)->diffInDays(now());
            $bucket = match (true) {
                $days <= 0 => 'current',
                $days <= 30 => '1_30',
                $days <= 60 => '31_60',
                $days <= 90 => '61_90',
                default => '90_plus',
            };
            $aging[$bucket] = $this->money->normalizeMoney($aging[$bucket] + $outstanding);
            $aging['total'] = $this->money->normalizeMoney($aging['total'] + $outstanding);
        }

        return $aging;
    }

    public function statementSnapshot(User $customer, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $from = $dateFrom ? CarbonImmutable::parse($dateFrom)->startOfDay() : null;
        $to = $dateTo ? CarbonImmutable::parse($dateTo)->endOfDay() : null;
        $opening = 0.0;
        if ($from) {
            $openingEntries = CustomerLedgerEntry::query()
                ->where('customer_id', $customer->id)
                ->where('occurred_at', '<', $from)
                ->get();
            $opening = $this->entryBalance($openingEntries);
        }
        $entries = $this->customerLedger($customer, [
            'date_from' => $from?->toDateString(),
            'date_to' => $to?->toDateString(),
        ])->get();
        $running = $opening;
        $rows = $entries->map(function (CustomerLedgerEntry $entry) use (&$running) {
            $debit = $entry->direction === 'debit' ? (float) $entry->amount : 0.0;
            $credit = $entry->direction === 'credit' ? (float) $entry->amount : 0.0;
            $running = $this->money->normalizeMoney($running + $debit - $credit);

            return [
                'date' => $entry->occurred_at?->format('Y-m-d H:i'),
                'entry_type' => $entry->entry_type,
                'reference' => $entry->order?->code
                    ?: $entry->payment?->reference
                    ?: $entry->salesReturn?->return_number
                    ?: '#'.$entry->id,
                'description' => $entry->description,
                'debit' => $debit,
                'credit' => $credit,
                'running_balance' => $running,
            ];
        });
        $charges = $this->money->normalizeMoney($rows->sum('debit'));
        $credits = $this->money->normalizeMoney($rows->sum('credit'));

        return [
            'customer' => $customer,
            'dateFrom' => $from?->toDateString(),
            'dateTo' => $to?->toDateString(),
            'openingBalance' => $opening,
            'rows' => $rows,
            'totals' => [
                'charges' => $charges,
                'credits' => $credits,
                'closingBalance' => $this->money->normalizeMoney($opening + $charges - $credits),
            ],
            'isOperationalStatement' => false,
        ];
    }

    public function summary(): array
    {
        if (! $this->enabled()) {
            return [
                'enabled' => false,
                'total_outstanding' => 0.0,
                'customers_with_balance' => 0,
                'aging' => $this->emptyAging(),
            ];
        }

        $balances = CustomerLedgerEntry::query()
            ->selectRaw("customer_id, SUM(CASE WHEN direction = 'debit' THEN amount ELSE -amount END) as balance")
            ->groupBy('customer_id')
            ->get();

        return [
            'enabled' => true,
            'total_outstanding' => $this->money->normalizeMoney(
                $balances->sum(fn ($row) => max(0, (float) $row->balance))
            ),
            'customers_with_balance' => $balances
                ->filter(fn ($row) => (float) $row->balance > 0.000001)
                ->count(),
            'aging' => $this->agingSummary(),
        ];
    }

    private function validatedShift(string $method, ?CashierShift $shift, User $receiver): ?CashierShift
    {
        if ($method !== 'cash') {
            return null;
        }
        if (! $shift) {
            throw new DomainException('Cash customer payments require an open cashbox shift.');
        }
        $shift = CashierShift::query()->with('cashbox')->lockForUpdate()->findOrFail($shift->id);
        if (! $shift->isOpen()) {
            throw new DomainException('Cash customer payments require an open cashbox shift.');
        }
        if (
            $receiver->user_type !== 'admin'
            && $receiver->hasRole('cashier')
            && ! $receiver->hasAnyRole(['owner_general_manager', 'store_admin', 'accountant'])
            && (int) $shift->opened_by !== (int) $receiver->id
        ) {
            throw new DomainException('Cashiers can receive customer payments only into their own open shift.');
        }

        return $shift;
    }

    private function outstandingAmountForEntry(CustomerLedgerEntry $entry): float
    {
        return max(0, $this->money->normalizeMoney(
            (float) $entry->amount - (float) $entry->allocations()->sum('amount')
        ));
    }

    private function entryBalance(Collection $entries): float
    {
        return $this->money->normalizeMoney($entries->sum(
            fn (CustomerLedgerEntry $entry) => $entry->direction === 'debit'
                ? (float) $entry->amount
                : -(float) $entry->amount
        ));
    }

    private function emptyAging(): array
    {
        return ['current' => 0.0, '1_30' => 0.0, '31_60' => 0.0, '61_90' => 0.0, '90_plus' => 0.0, 'total' => 0.0];
    }

    private function positiveAmount(mixed $amount, string $message): float
    {
        if (! is_numeric($amount)) {
            throw new DomainException($message);
        }
        $amount = $this->money->normalizeMoney($amount);
        if ($amount <= 0) {
            throw new DomainException($message);
        }

        return $amount;
    }

    private function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw new DomainException('Customer accounts are disabled.');
        }
    }
}
