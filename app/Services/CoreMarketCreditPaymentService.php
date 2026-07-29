<?php

namespace App\Services;

use App\Models\CustomerLedgerEntry;
use App\Models\Order;
use App\Models\StoreBranch;
use App\Models\User;
use DomainException;

class CoreMarketCreditPaymentService
{
    public const METHOD = 'pay_on_account';

    public function __construct(
        private CoreMarketCustomerAccountFeatureService $features,
        private CoreMarketCustomerCreditService $credit,
        private CoreMarketCustomerReceivableService $receivables
    ) {
    }

    public function posEnabled(): bool
    {
        return $this->features->posPayOnAccountEnabled();
    }

    public function webEnabled(): bool
    {
        return $this->features->checkoutPayOnAccountEnabled();
    }

    public function canUsePos(User $actor): bool
    {
        return $this->posEnabled()
            && ($actor->user_type === 'admin' || $actor->can('customer_credit.pay_on_account_pos'));
    }

    public function decision(User $customer, mixed $amount, string $channel): array
    {
        $featureEnabled = match ($channel) {
            'pos' => $this->posEnabled(),
            'web_checkout' => $this->webEnabled(),
            default => false,
        };

        if (! $featureEnabled) {
            return $this->deniedDecision($customer, $amount, 'feature_disabled');
        }

        $profile = $this->credit->getProfile($customer);
        if (! $profile) {
            return $this->deniedDecision($customer, $amount, 'account_disabled');
        }
        if ($profile->account_status === 'on_hold') {
            return $this->deniedDecision($customer, $amount, 'account_on_hold');
        }
        if ($profile->account_status === 'blocked') {
            return $this->deniedDecision($customer, $amount, 'account_blocked');
        }
        if (! $profile->is_credit_allowed) {
            return $this->deniedDecision($customer, $amount, 'credit_not_allowed');
        }

        return $this->credit->creditDecision($customer, $amount);
    }

    public function assertEligible(
        User $customer,
        mixed $amount,
        string $channel,
        ?User $actor = null
    ): array {
        if ($customer->user_type !== 'customer' || $customer->banned) {
            throw new DomainException('Pay on Account requires an active customer.');
        }
        if ($channel === 'pos' && (! $actor || ! $this->canUsePos($actor))) {
            throw new DomainException('You are not allowed to complete Pay on Account POS sales.');
        }

        $decision = $this->decision($customer, $amount, $channel);
        if (! $decision['allowed']) {
            throw new DomainException($this->credit->decisionMessage($decision['reason']));
        }

        return $decision;
    }

    public function postOrder(
        Order $order,
        User $actor,
        string $channel,
        ?StoreBranch $branch = null
    ): CustomerLedgerEntry {
        $order->loadMissing('user');
        if (! $order->user) {
            throw new DomainException('Pay on Account requires an identified customer.');
        }

        $this->assertEligible($order->user, $order->grand_total, $channel, $channel === 'pos' ? $actor : null);

        return $this->receivables->createInvoiceEntryFromOrder($order, $actor, [
            'idempotency_key' => 'order:'.$order->id.':pay_on_account',
            'source' => $channel,
            'payment_method' => self::METHOD,
            'branch_id' => $branch?->id ?? data_get($order->pos_metadata, 'store_branch_id'),
        ]);
    }

    public function reasonMessage(string $reason): string
    {
        return $this->credit->decisionMessage($reason);
    }

    private function deniedDecision(User $customer, mixed $amount, string $reason): array
    {
        $base = $this->credit->creditDecision($customer, $amount);

        return array_replace($base, [
            'allowed' => false,
            'reason' => $reason,
        ]);
    }
}
