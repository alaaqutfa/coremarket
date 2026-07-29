<?php

namespace App\Services;

use App\Models\CustomerAccountProfile;
use App\Models\CustomerLedgerEntry;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Schema;

class CoreMarketCustomerCreditService
{
    public function __construct(
        private CoreMarketCustomerAccountFeatureService $features,
        private CoreMarketMoneyService $money
    ) {
    }

    public function getProfile(User $customer): ?CustomerAccountProfile
    {
        if (! Schema::hasTable('customer_account_profiles')) {
            return null;
        }

        return CustomerAccountProfile::query()->where('customer_id', $customer->id)->first();
    }

    public function updateProfile(User $customer, array $data, User $actor): CustomerAccountProfile
    {
        $this->assertCustomer($customer);
        if (! $this->features->enabled() || ! Schema::hasTable('customer_account_profiles')) {
            throw new DomainException('Customer accounts are not enabled.');
        }

        $status = $data['account_status'] ?? 'active';
        if (! in_array($status, ['active', 'on_hold', 'blocked'], true)) {
            throw new DomainException('Customer account status is invalid.');
        }

        $creditLimit = filled($data['credit_limit'] ?? null)
            ? max(0, $this->money->normalizeMoney($data['credit_limit']))
            : null;
        $terms = filled($data['payment_terms_days'] ?? null)
            ? max(0, min(3650, (int) $data['payment_terms_days']))
            : null;

        return CustomerAccountProfile::query()->updateOrCreate(
            ['customer_id' => $customer->id],
            [
                'is_credit_allowed' => (bool) ($data['is_credit_allowed'] ?? false),
                'credit_limit' => $creditLimit,
                'credit_limit_currency' => $this->money->baseCurrency(),
                'payment_terms_days' => $terms,
                'account_status' => $status,
                'default_payment_method' => $data['default_payment_method'] ?? null,
                'notes' => $data['notes'] ?? null,
                'last_reviewed_at' => now(),
                'reviewed_by_user_id' => $actor->id,
            ]
        );
    }

    public function currentBalance(User $customer): float
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

    public function creditLimit(User $customer): ?float
    {
        $limit = $this->getProfile($customer)?->credit_limit;

        return $limit === null ? null : $this->money->normalizeMoney($limit);
    }

    public function availableCredit(User $customer): ?float
    {
        $limit = $this->creditLimit($customer);
        if ($limit === null) {
            return null;
        }

        return max(0, $this->money->normalizeMoney($limit - max(0, $this->currentBalance($customer))));
    }

    public function overdueBalance(User $customer): float
    {
        if (! $this->features->paymentTermsEnabled() || ! Schema::hasTable('customer_ledger_entries')) {
            return 0.0;
        }

        return $this->money->normalizeMoney(
            $this->invoiceEntries($customer)
                ->filter(fn (CustomerLedgerEntry $entry) => $this->dueDate($entry)?->isBefore(today()))
                ->sum(fn (CustomerLedgerEntry $entry) => $this->outstandingAmount($entry))
        );
    }

    public function nextDueDate(User $customer): ?CarbonImmutable
    {
        if (! $this->features->paymentTermsEnabled()) {
            return null;
        }

        return $this->invoiceEntries($customer)
            ->filter(fn (CustomerLedgerEntry $entry) => $this->outstandingAmount($entry) > 0)
            ->map(fn (CustomerLedgerEntry $entry) => $this->dueDate($entry))
            ->filter()
            ->sort()
            ->first();
    }

    public function creditDecision(User $customer, mixed $orderAmount): array
    {
        $amount = max(0, $this->money->normalizeMoney($orderAmount));
        $balance = $this->currentBalance($customer);
        $profile = $this->getProfile($customer);
        $limit = $profile?->credit_limit === null
            ? null
            : $this->money->normalizeMoney($profile->credit_limit);
        $overdue = $this->overdueBalance($customer);
        $projected = $this->money->normalizeMoney($balance + $amount);
        $reason = 'ok';

        if (! $this->features->enabled()) {
            $reason = 'feature_disabled';
        } elseif ($this->features->creditLimitsEnabled()) {
            $reason = match (true) {
                ! $profile => 'account_disabled',
                $profile->account_status === 'on_hold' => 'account_on_hold',
                $profile->account_status === 'blocked' => 'account_blocked',
                ! $profile->is_credit_allowed => 'credit_not_allowed',
                $limit !== null && $projected - $limit > 0.000001 => 'over_credit_limit',
                default => 'ok',
            };
        }

        if ($reason === 'ok' && $this->features->paymentTermsEnabled() && $overdue > 0.000001) {
            $reason = 'overdue_balance';
        }

        return [
            'allowed' => $reason === 'ok',
            'reason' => $reason,
            'current_balance' => $balance,
            'order_amount' => $amount,
            'projected_balance' => $projected,
            'credit_limit' => $limit,
            'available_credit' => $limit === null ? null : max(0, $this->money->normalizeMoney($limit - max(0, $balance))),
            'overdue_amount' => $overdue,
        ];
    }

    public function canPostOrderToAccount(Order $order): array
    {
        $order->loadMissing('user');
        if (! $order->user || $order->user->user_type !== 'customer') {
            return [
                'allowed' => false,
                'reason' => 'account_disabled',
                'current_balance' => 0.0,
                'order_amount' => $this->money->normalizeMoney($order->grand_total),
                'projected_balance' => $this->money->normalizeMoney($order->grand_total),
                'credit_limit' => null,
                'available_credit' => null,
                'overdue_amount' => 0.0,
            ];
        }

        return $this->creditDecision($order->user, $order->grand_total);
    }

    public function decisionMessage(string $reason): string
    {
        return match ($reason) {
            'feature_disabled' => 'Customer accounts are disabled.',
            'account_disabled' => 'This customer does not have an active credit profile.',
            'account_on_hold' => 'This customer account is on hold.',
            'account_blocked' => 'This customer account is blocked.',
            'credit_not_allowed' => 'This customer is not allowed to buy on account.',
            'over_credit_limit' => 'Posting this order would exceed the customer credit limit.',
            'overdue_balance' => 'This customer has overdue invoices that must be reviewed before new credit is posted.',
            default => 'Customer credit policy allows this order.',
        };
    }

    public function summary(): array
    {
        if (! $this->features->enabled() || ! Schema::hasTable('customer_account_profiles')) {
            return [
                'profiles_count' => 0,
                'total_credit_limit' => 0.0,
                'available_credit' => 0.0,
                'overdue_balance' => 0.0,
            ];
        }

        $profiles = CustomerAccountProfile::query()->with('customer')->get();

        return [
            'profiles_count' => $profiles->count(),
            'total_credit_limit' => $this->money->normalizeMoney($profiles->sum('credit_limit')),
            'available_credit' => $this->money->normalizeMoney($profiles->sum(
                fn (CustomerAccountProfile $profile) => $this->availableCredit($profile->customer) ?? 0
            )),
            'overdue_balance' => $this->money->normalizeMoney($profiles->sum(
                fn (CustomerAccountProfile $profile) => $this->overdueBalance($profile->customer)
            )),
        ];
    }

    public function dueDate(CustomerLedgerEntry $entry): ?CarbonImmutable
    {
        $value = $entry->metadata['due_date'] ?? null;

        return filled($value) ? CarbonImmutable::parse($value)->startOfDay() : null;
    }

    private function invoiceEntries(User $customer)
    {
        return CustomerLedgerEntry::query()
            ->with('allocations')
            ->where('customer_id', $customer->id)
            ->where('entry_type', 'invoice')
            ->get();
    }

    private function outstandingAmount(CustomerLedgerEntry $entry): float
    {
        return max(0, $this->money->normalizeMoney(
            (float) $entry->amount - (float) $entry->allocations->sum('amount')
        ));
    }

    private function assertCustomer(User $customer): void
    {
        if ($customer->user_type !== 'customer') {
            throw new DomainException('Credit profiles can be managed only for customer accounts.');
        }
    }
}
