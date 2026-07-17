<?php

namespace App\Services;

use App\Models\LoyaltyAccount;
use App\Models\LoyaltyPointMovement;
use App\Models\LoyaltyRule;
use App\Models\Order;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class LoyaltyPointsService
{
    public function accountForCustomer(User $customer): LoyaltyAccount
    {
        $this->assertCustomer($customer);

        return LoyaltyAccount::query()->firstOrCreate(
            ['user_id' => $customer->id],
            [
                'points_balance' => 0,
                'lifetime_points_earned' => 0,
                'lifetime_points_redeemed' => 0,
                'status' => 'active',
            ]
        );
    }

    public function getBalance(User $customer): int
    {
        return (int) $this->accountForCustomer($customer)->points_balance;
    }

    public function balanceForCustomerWithoutCreatingAccount(User $customer): int
    {
        $this->assertCustomer($customer);

        return (int) (LoyaltyAccount::query()
            ->where('user_id', $customer->id)
            ->value('points_balance') ?? 0);
    }

    public function activeRuleForOrder(?Order $order = null): ?LoyaltyRule
    {
        return LoyaltyRule::query()
            ->where('is_active', true)
            ->when($order, function (Builder $query) use ($order) {
                $query->where(function (Builder $ruleQuery) use ($order) {
                    $ruleQuery->whereNull('applies_to_order_from')
                        ->orWhere('applies_to_order_from', $order->order_from);
                });
            })
            ->orderBy('id')
            ->first();
    }

    public function saveRule(array $payload, ?LoyaltyRule $rule = null): LoyaltyRule
    {
        $rule ??= new LoyaltyRule();
        $rule->fill($payload);
        $rule->save();

        return $rule->fresh();
    }

    public function eligibleOrder(Order $order): bool
    {
        if (! $order->user_id || $order->guest_id) {
            return false;
        }

        $customer = $order->relationLoaded('user') ? $order->user : User::query()->find($order->user_id);
        if (! $customer || $customer->user_type !== 'customer' || $customer->banned) {
            return false;
        }

        if ($order->payment_status !== 'paid' || $order->delivery_status !== 'delivered') {
            return false;
        }

        if (in_array($order->payment_status, ['cancelled', 'refunded'], true)
            || in_array($order->delivery_status, ['cancelled', 'returned'], true)) {
            return false;
        }

        if ($order->salesReturns()->where('status', 'completed')->exists()) {
            return false;
        }

        $rule = $this->activeRuleForOrder($order);

        return $rule !== null
            && is_numeric($order->grand_total)
            && (float) $order->grand_total >= (float) $rule->min_order_amount;
    }

    public function previewEarnForOrder(Order $order): int
    {
        if (! $this->eligibleOrder($order)) {
            return 0;
        }

        $rule = $this->activeRuleForOrder($order);
        if (! $rule || (float) $rule->earn_rate_amount <= 0 || (int) $rule->earn_rate_points <= 0) {
            return 0;
        }

        return max(0, (int) floor(((float) $order->grand_total / (float) $rule->earn_rate_amount) * (int) $rule->earn_rate_points));
    }

    public function earnForOrder(Order $order): ?LoyaltyPointMovement
    {
        if (! $this->eligibleOrder($order)) {
            return null;
        }

        $points = $this->previewEarnForOrder($order);
        if ($points <= 0) {
            return null;
        }

        $idempotencyKey = "loyalty:earn:order:{$order->id}";

        return DB::transaction(function () use ($order, $points, $idempotencyKey) {
            $existing = LoyaltyPointMovement::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                return $existing;
            }

            $customer = User::query()->findOrFail($order->user_id);
            $account = $this->accountForCustomer($customer);
            $account = LoyaltyAccount::query()->lockForUpdate()->findOrFail($account->id);

            $newBalance = (int) $account->points_balance + $points;
            $movement = LoyaltyPointMovement::query()->create([
                'loyalty_account_id' => $account->id,
                'user_id' => $customer->id,
                'movement_type' => 'earn',
                'direction' => 'in',
                'points' => $points,
                'balance_after' => $newBalance,
                'reference_type' => Order::class,
                'reference_id' => $order->id,
                'idempotency_key' => $idempotencyKey,
                'reason' => 'Earned from order',
                'metadata' => [
                    'order_code' => $order->code,
                    'grand_total' => (float) $order->grand_total,
                    'rule_id' => $this->activeRuleForOrder($order)?->id,
                ],
            ]);

            $account->points_balance = $newBalance;
            $account->lifetime_points_earned = (int) $account->lifetime_points_earned + $points;
            $account->save();

            return $movement;
        });
    }

    public function pointsEarnedForOrder(Order $order): ?LoyaltyPointMovement
    {
        return LoyaltyPointMovement::query()
            ->where('reference_type', Order::class)
            ->where('reference_id', $order->id)
            ->where('movement_type', 'earn')
            ->first();
    }

    public function attemptEarnForOrder(Order $order): ?LoyaltyPointMovement
    {
        try {
            return $this->earnForOrder($order);
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }
    }

    public function reverseForOrder(Order $order, string $reason, ?User $actor = null): ?LoyaltyPointMovement
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('A loyalty reversal reason is required.');
        }

        $idempotencyKey = "loyalty:reverse:order:{$order->id}";

        return DB::transaction(function () use ($order, $reason, $actor, $idempotencyKey) {
            $existing = LoyaltyPointMovement::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                return $existing;
            }

            $earned = LoyaltyPointMovement::query()
                ->where('idempotency_key', "loyalty:earn:order:{$order->id}")
                ->first();
            if (! $earned) {
                return null;
            }

            $account = LoyaltyAccount::query()->lockForUpdate()->findOrFail($earned->loyalty_account_id);
            $reversedPoints = min((int) $earned->points, (int) $account->points_balance);
            if ($reversedPoints <= 0) {
                return null;
            }

            $newBalance = (int) $account->points_balance - $reversedPoints;
            $movement = LoyaltyPointMovement::query()->create([
                'loyalty_account_id' => $account->id,
                'user_id' => $earned->user_id,
                'movement_type' => 'reverse',
                'direction' => 'out',
                'points' => $reversedPoints,
                'balance_after' => $newBalance,
                'reference_type' => Order::class,
                'reference_id' => $order->id,
                'idempotency_key' => $idempotencyKey,
                'reason' => $reason,
                'created_by' => $actor?->id,
                'metadata' => [
                    'earned_movement_id' => $earned->id,
                    'partial_reverse' => $reversedPoints < (int) $earned->points,
                ],
            ]);

            $account->points_balance = $newBalance;
            $account->save();

            return $movement;
        });
    }

    public function attemptReverseForOrder(Order $order, string $reason, ?User $actor = null): ?LoyaltyPointMovement
    {
        try {
            return $this->reverseForOrder($order, $reason, $actor);
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }
    }

    public function adjustPoints(User $customer, int $points, string $reason, User $admin): LoyaltyPointMovement
    {
        $reason = trim($reason);
        if ($points === 0) {
            throw new DomainException('Loyalty adjustment points cannot be zero.');
        }
        if ($reason === '') {
            throw new DomainException('A loyalty adjustment reason is required.');
        }

        return DB::transaction(function () use ($customer, $points, $reason, $admin) {
            $account = $this->accountForCustomer($customer);
            $account = LoyaltyAccount::query()->lockForUpdate()->findOrFail($account->id);
            $isInbound = $points > 0;
            $amount = abs($points);

            if (! $isInbound && (int) $account->points_balance < $amount) {
                throw new DomainException('Loyalty adjustment cannot make balance negative.');
            }

            $newBalance = $isInbound
                ? (int) $account->points_balance + $amount
                : (int) $account->points_balance - $amount;

            $movement = LoyaltyPointMovement::query()->create([
                'loyalty_account_id' => $account->id,
                'user_id' => $customer->id,
                'movement_type' => 'adjustment',
                'direction' => $isInbound ? 'in' : 'out',
                'points' => $amount,
                'balance_after' => $newBalance,
                'reason' => $reason,
                'created_by' => $admin->id,
            ]);

            $account->points_balance = $newBalance;
            $account->save();

            return $movement;
        });
    }

    public function movementRows(array $filters = []): Builder
    {
        return LoyaltyPointMovement::query()
            ->with(['account', 'user', 'creator'])
            ->when($filters['user_id'] ?? null, fn (Builder $query, $userId) => $query->where('user_id', $userId))
            ->when($filters['movement_type'] ?? null, fn (Builder $query, $type) => $query->where('movement_type', $type))
            ->when($filters['direction'] ?? null, fn (Builder $query, $direction) => $query->where('direction', $direction))
            ->when($filters['reference_type'] ?? null, fn (Builder $query, $type) => $query->where('reference_type', $type))
            ->when($filters['reference_id'] ?? null, fn (Builder $query, $id) => $query->where('reference_id', $id))
            ->when($filters['date_from'] ?? null, fn (Builder $query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, $date) => $query->whereDate('created_at', '<=', $date))
            ->latest();
    }

    private function assertCustomer(User $customer): void
    {
        if ($customer->user_type !== 'customer') {
            throw new DomainException('Loyalty accounts are only available for customers.');
        }
    }
}
