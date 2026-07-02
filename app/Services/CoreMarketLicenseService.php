<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;

class CoreMarketLicenseService
{
    public function isEnabled(): bool
    {
        return (bool) config('coremarket.license.license_enabled', false);
    }

    public function status(): string
    {
        $status = strtolower((string) config('coremarket.license.status', 'active'));

        return in_array($status, ['active', 'suspended', 'expired'], true) ? $status : 'active';
    }

    public function isActive(?CarbonInterface $now = null): bool
    {
        if (! $this->isEnabled()) {
            return true;
        }

        return ! $this->isSuspended() && ! $this->isExpired($now);
    }

    public function isExpired(?CarbonInterface $now = null): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        if ($this->status() === 'expired') {
            return true;
        }

        $expiresAt = $this->expiresAt();

        return $expiresAt !== null
            && $this->resolveNow($now)->greaterThan($expiresAt);
    }

    public function isSuspended(): bool
    {
        return $this->isEnabled() && $this->status() === 'suspended';
    }

    public function isInGracePeriod(?CarbonInterface $now = null): bool
    {
        if (! $this->isExpired($now)) {
            return false;
        }

        $graceUntil = $this->graceUntil();

        return $graceUntil !== null
            && ! $this->resolveNow($now)->greaterThan($graceUntil);
    }

    public function canManageStore($user = null, ?CarbonInterface $now = null): bool
    {
        if ($this->canBypass($user) || ! $this->isEnabled()) {
            return true;
        }

        if ($this->isSuspended()) {
            return false;
        }

        if ($this->isExpired($now) && ! $this->isInGracePeriod($now)) {
            return false;
        }

        return true;
    }

    public function canAcceptOrders($user = null, ?CarbonInterface $now = null): bool
    {
        if ($this->canBypass($user) || ! $this->isEnabled()) {
            return true;
        }

        if ($this->isSuspended()) {
            return false;
        }

        if ($this->isExpired($now) && ! $this->isInGracePeriod($now)) {
            return false;
        }

        return true;
    }

    public function currentPlan(): ?string
    {
        return config('coremarket.license.plan_code', config('coremarket.plan.code'));
    }

    public function limit(string $key, $default = null)
    {
        return config("coremarket.limits.{$key}", $default);
    }

    public function feature(string $key, $default = null)
    {
        return config("coremarket.features.{$key}", $default);
    }

    public function snapshot(): array
    {
        return [
            'license_enabled' => $this->isEnabled(),
            'instance_id' => config('coremarket.license.instance_id'),
            'license_key' => config('coremarket.license.license_key'),
            'domain' => config('coremarket.license.domain'),
            'plan_code' => $this->currentPlan(),
            'status' => $this->status(),
            'starts_at' => config('coremarket.license.starts_at'),
            'expires_at' => config('coremarket.license.expires_at'),
            'grace_until' => config('coremarket.license.grace_until'),
            'suspension_reason' => config('coremarket.license.suspension_reason'),
            'limits' => config('coremarket.limits', []),
            'features' => config('coremarket.features', []),
        ];
    }

    public function currentProductCount(bool $publishedOnly = false): int
    {
        $query = Product::query()
            ->where('auction_product', 0)
            ->where('wholesale_product', 0);

        if ($publishedOnly) {
            $query->where('published', 1);
        }

        return $query->count();
    }

    public function canCreateProducts(int $incomingCount = 1, ?int $currentCount = null): bool
    {
        return ! $this->wouldExceedProductLimit($incomingCount, $currentCount);
    }

    public function canPublishProduct(bool $isCurrentlyPublished = false, ?int $publishedCount = null): bool
    {
        if ($isCurrentlyPublished) {
            return true;
        }

        $limit = (int) $this->limit('products_limit', 0);

        if ($limit < 1) {
            return true;
        }

        $currentCount = $publishedCount ?? $this->currentProductCount(true);

        return ($currentCount + 1) <= $limit;
    }

    public function currentMonthlyOrderCount(?CarbonInterface $now = null): int
    {
        $now = $this->resolveNow($now);

        return Order::query()
            ->whereBetween('created_at', [
                $now->copy()->startOfMonth(),
                $now->copy()->endOfMonth(),
            ])
            ->count();
    }

    public function canCreateOrders(int $incomingCount = 1, ?int $currentCount = null, ?CarbonInterface $now = null): bool
    {
        return ! $this->wouldExceedMonthlyOrderLimit($incomingCount, $currentCount, $now);
    }

    public function managementLockMessage(): string
    {
        return 'Your store subscription is inactive. Please contact support.';
    }

    public function orderLockMessage(): string
    {
        return 'Your store subscription is inactive. Please contact support.';
    }

    public function productLimitMessage(): string
    {
        return 'Your current plan allows up to ' . $this->limit('products_limit', 50) . ' products. Please contact support to upgrade.';
    }

    public function monthlyOrderLimitMessage(): string
    {
        return 'Monthly order limit reached. Please contact the store owner or support.';
    }

    protected function wouldExceedProductLimit(int $incomingCount = 1, ?int $currentCount = null): bool
    {
        $limit = (int) $this->limit('products_limit', 0);

        if ($limit < 1) {
            return false;
        }

        $currentCount = $currentCount ?? $this->currentProductCount();

        return ($currentCount + $incomingCount) > $limit;
    }

    protected function wouldExceedMonthlyOrderLimit(int $incomingCount = 1, ?int $currentCount = null, ?CarbonInterface $now = null): bool
    {
        $limit = (int) $this->limit('monthly_orders_limit', 0);

        if ($limit < 1) {
            return false;
        }

        $currentCount = $currentCount ?? $this->currentMonthlyOrderCount($now);

        return ($currentCount + $incomingCount) > $limit;
    }

    protected function canBypass($user): bool
    {
        if (! $user) {
            return false;
        }

        if (($user->user_type ?? null) === 'admin') {
            return true;
        }

        return method_exists($user, 'hasRole') && $user->hasRole('Super Admin');
    }

    protected function expiresAt(): ?CarbonInterface
    {
        return $this->parseDate(config('coremarket.license.expires_at'));
    }

    protected function graceUntil(): ?CarbonInterface
    {
        return $this->parseDate(config('coremarket.license.grace_until'));
    }

    protected function parseDate($value): ?CarbonInterface
    {
        if (! $value) {
            return null;
        }

        return Carbon::parse($value);
    }

    protected function resolveNow(?CarbonInterface $now = null): CarbonInterface
    {
        return $now ? Carbon::instance($now) : now();
    }
}
