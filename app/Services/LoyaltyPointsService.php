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

    public function activeRedemptionRuleForOrderFrom(?string $orderFrom = 'pos'): ?LoyaltyRule
    {
        return LoyaltyRule::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->first(fn (LoyaltyRule $rule) => $rule->hasRedemptionEnabledForOrderFrom($orderFrom));
    }

    public function previewRedeemForCustomer(User $customer, int $points, float $grossTotal, ?string $orderFrom = 'pos'): array
    {
        $this->assertRedeemableCustomer($customer);

        $balance = (int) (LoyaltyAccount::query()
            ->where('user_id', $customer->id)
            ->value('points_balance') ?? 0);

        return $this->buildRedemptionPreview($customer, $points, $grossTotal, $orderFrom, $balance);
    }

    public function redeemForOrder(Order $order, User $customer, int $points, ?User $actor = null): LoyaltyPointMovement
    {
        $this->assertRedeemableCustomer($customer);
        if ((int) $order->user_id !== (int) $customer->id) {
            throw new DomainException('Loyalty redemption customer must match the order customer.');
        }

        $idempotencyKey = "loyalty:redeem:order:{$order->id}";

        return DB::transaction(function () use ($order, $customer, $points, $actor, $idempotencyKey) {
            $existing = LoyaltyPointMovement::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                if ((int) $existing->user_id !== (int) $customer->id || (int) $existing->points !== $points) {
                    throw new DomainException('Order already has a different loyalty redemption.');
                }

                return $existing;
            }

            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ((int) $lockedOrder->loyalty_points_redeemed > 0 || (float) $lockedOrder->loyalty_redemption_discount > 0) {
                throw new DomainException('Order already has a different loyalty redemption.');
            }

            $account = LoyaltyAccount::query()
                ->where('user_id', $customer->id)
                ->lockForUpdate()
                ->first();
            if (! $account || ! $account->isActive()) {
                throw new DomainException('Insufficient loyalty points balance.');
            }

            $preview = $this->buildRedemptionPreview(
                $customer,
                $points,
                (float) $lockedOrder->grand_total,
                $lockedOrder->order_from,
                (int) $account->points_balance,
            );

            $movement = LoyaltyPointMovement::query()->create([
                'loyalty_account_id' => $account->id,
                'user_id' => $customer->id,
                'movement_type' => 'redeem',
                'direction' => 'out',
                'points' => $preview['used_points'],
                'balance_after' => $preview['balance_after'],
                'reference_type' => Order::class,
                'reference_id' => $lockedOrder->id,
                'idempotency_key' => $idempotencyKey,
                'reason' => 'Redeemed for POS order',
                'created_by' => $actor?->id,
                'metadata' => [
                    'rule_id' => $preview['rule_id'],
                    'order_id' => $lockedOrder->id,
                    'order_code' => $lockedOrder->code,
                    'order_from' => $lockedOrder->order_from,
                    'gross_total' => $preview['gross_total'],
                    'discount_amount' => $preview['discount_amount'],
                    'final_total' => $preview['final_total'],
                    'cashier_id' => $lockedOrder->cashier_id,
                    'actor_id' => $actor?->id,
                    'policy' => 'pos_order_level_discount',
                ],
            ]);

            $account->points_balance = $preview['balance_after'];
            $account->lifetime_points_redeemed = (int) $account->lifetime_points_redeemed + $preview['used_points'];
            $account->save();

            $metadata = (array) ($lockedOrder->pos_metadata ?? []);
            $metadata['loyalty_redemption'] = [
                'rule_id' => $preview['rule_id'],
                'gross_total' => $preview['gross_total'],
                'points_redeemed' => $preview['used_points'],
                'discount_amount' => $preview['discount_amount'],
                'final_total' => $preview['final_total'],
            ];
            $lockedOrder->loyalty_points_redeemed = $preview['used_points'];
            $lockedOrder->loyalty_redemption_discount = $preview['discount_amount'];
            $lockedOrder->grand_total = $preview['final_total'];
            $lockedOrder->pos_metadata = $metadata;
            $lockedOrder->save();

            return $movement;
        });
    }

    public function pointsRedeemedForOrder(Order $order): ?LoyaltyPointMovement
    {
        return LoyaltyPointMovement::query()
            ->where('reference_type', Order::class)
            ->where('reference_id', $order->id)
            ->where('movement_type', 'redeem')
            ->first();
    }

    public function restoreRedeemedForOrder(Order $order, string $reason = 'return', ?User $actor = null): ?LoyaltyPointMovement
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('A loyalty redemption restore reason is required.');
        }

        $idempotencyKey = "loyalty:redeem-restore:order:{$order->id}";

        return DB::transaction(function () use ($order, $reason, $actor, $idempotencyKey) {
            $existing = LoyaltyPointMovement::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing;
            }

            $redeem = $this->pointsRedeemedForOrder($order);
            if (! $redeem) {
                return null;
            }

            $account = LoyaltyAccount::query()->lockForUpdate()->findOrFail($redeem->loyalty_account_id);
            $newBalance = (int) $account->points_balance + (int) $redeem->points;
            $movement = LoyaltyPointMovement::query()->create([
                'loyalty_account_id' => $account->id,
                'user_id' => $redeem->user_id,
                'movement_type' => 'redeem_restore',
                'direction' => 'in',
                'points' => $redeem->points,
                'balance_after' => $newBalance,
                'reference_type' => Order::class,
                'reference_id' => $order->id,
                'idempotency_key' => $idempotencyKey,
                'reason' => $reason,
                'created_by' => $actor?->id,
                'metadata' => [
                    'original_redeem_movement_id' => $redeem->id,
                    'reason' => $reason,
                    'policy' => 'full_restore_on_first_completed_return',
                    'actor_id' => $actor?->id,
                ],
            ]);

            $account->points_balance = $newBalance;
            $account->save();

            return $movement;
        });
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

    private function assertRedeemableCustomer(User $customer): void
    {
        $this->assertCustomer($customer);

        if ($customer->banned) {
            throw new DomainException('Selected loyalty customer is unavailable.');
        }
    }

    private function buildRedemptionPreview(User $customer, int $points, float $grossTotal, ?string $orderFrom, int $balance): array
    {
        if ($points <= 0) {
            throw new DomainException('Redemption points must be greater than zero.');
        }
        if ($grossTotal <= 0) {
            throw new DomainException('Redemption gross total must be greater than zero.');
        }

        $rule = $this->activeRedemptionRuleForOrderFrom($orderFrom);
        if (! $rule) {
            throw new DomainException('Loyalty redemption is disabled.');
        }
        if ($points < (int) $rule->min_redeem_points) {
            throw new DomainException('Redemption points are below the minimum.');
        }
        if ($points > $balance) {
            throw new DomainException('Insufficient loyalty points balance.');
        }
        if ($rule->max_redeem_points_per_order !== null && $points > (int) $rule->max_redeem_points_per_order) {
            throw new DomainException('Redemption points exceed the per-order cap.');
        }

        $units = intdiv($points, (int) $rule->redeem_points);
        $usedPoints = $units * (int) $rule->redeem_points;
        if ($usedPoints <= 0) {
            throw new DomainException('Redemption points do not match the rule conversion.');
        }

        $discount = $this->roundMoney($units * (float) $rule->redeem_value);
        if ($rule->max_redeem_percent !== null) {
            $maxDiscount = $this->roundMoney($grossTotal * ((float) $rule->max_redeem_percent / 100));
            if ($discount > $maxDiscount) {
                throw new DomainException('Redemption discount exceeds the allowed percentage.');
            }
        }

        $finalTotal = $this->roundMoney($grossTotal - $discount);
        if ($discount <= 0 || $finalTotal < 0.01) {
            throw new DomainException('Redemption discount exceeds the allowed total.');
        }

        return [
            'enabled' => true,
            'rule_id' => $rule->id,
            'requested_points' => $points,
            'used_points' => $usedPoints,
            'discount_amount' => $discount,
            'gross_total' => $this->roundMoney($grossTotal),
            'final_total' => $finalTotal,
            'balance_before' => $balance,
            'balance_after' => $balance - $usedPoints,
            'redeem_points' => (int) $rule->redeem_points,
            'redeem_value' => (float) $rule->redeem_value,
            'order_from' => $orderFrom,
        ];
    }

    private function roundMoney(float $amount): float
    {
        return round($amount, 6);
    }
}
