<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;

class CoreMarketLicenseService
{
    protected ?CoreMarketFeatureAccessService $featureAccess = null;
    protected ?CoreMarketRuntimeSnapshotService $runtimeSnapshot = null;

    public function isEnabled(): bool
    {
        return $this->runtimeSnapshot()->hasAppliedSnapshot()
            || (bool) config('coremarket.license.license_enabled', false);
    }

    public function status(): string
    {
        $status = strtolower((string) ($this->runtimeSnapshot()->persistedStatus() ?? config('coremarket.license.status', 'active')));

        return in_array($status, ['active', 'inactive', 'suspended', 'expired'], true) ? $status : 'active';
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
        return $this->isEnabled() && in_array($this->status(), ['suspended', 'inactive'], true);
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
        return $this->featureAccess()->appliedPlan();
    }

    public function currentStoreMode(): string
    {
        return $this->featureAccess()->storeMode();
    }

    public function limit(string $key, $default = null)
    {
        return $this->featureAccess()->limit($key, $default);
    }

    public function feature(string $key, $default = null)
    {
        return $this->featureAccess()->value($key, $default);
    }

    public function snapshot(): array
    {
        $persistedStore = $this->runtimeSnapshot()->persistedStoreMetadata();
        $persistedSupport = $this->runtimeSnapshot()->persistedSupportMetadata();
        $matrix = $this->featureAccess()->matrixFor();
        $resolvedDomain = $persistedStore['store_url'] ?? config('coremarket.license.domain');

        if (is_string($resolvedDomain) && str_contains($resolvedDomain, '://')) {
            $resolvedDomain = parse_url($resolvedDomain, PHP_URL_HOST) ?: $resolvedDomain;
        }

        return [
            'license_enabled' => $this->isEnabled(),
            'instance_id' => config('coremarket.license.instance_id'),
            'license_key' => config('coremarket.license.license_key'),
            'domain' => $resolvedDomain,
            'plan_code' => $this->currentPlan(),
            'store_mode' => $this->currentStoreMode(),
            'status' => $this->status(),
            'starts_at' => config('coremarket.license.starts_at'),
            'expires_at' => config('coremarket.license.expires_at'),
            'grace_until' => config('coremarket.license.grace_until'),
            'suspension_reason' => config('coremarket.license.suspension_reason'),
            'limits' => $matrix['limits'],
            'features' => $matrix['features'],
            'store' => $persistedStore,
            'support' => $persistedSupport,
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

    public function productUsagePercentage(?int $currentCount = null): int
    {
        $limit = (int) $this->limit('products_limit', 0);

        if ($limit < 1) {
            return 0;
        }

        $currentCount = $currentCount ?? $this->currentProductCount();

        return (int) min(100, round(($currentCount / $limit) * 100));
    }

    public function monthlyOrderUsagePercentage(?int $currentCount = null, ?CarbonInterface $now = null): int
    {
        $limit = (int) $this->limit('monthly_orders_limit', 0);

        if ($limit < 1) {
            return 0;
        }

        $currentCount = $currentCount ?? $this->currentMonthlyOrderCount($now);

        return (int) min(100, round(($currentCount / $limit) * 100));
    }

    public function isProductLimitReached(?int $currentCount = null): bool
    {
        $limit = (int) $this->limit('products_limit', 0);

        if ($limit < 1) {
            return false;
        }

        $currentCount = $currentCount ?? $this->currentProductCount();

        return $currentCount >= $limit;
    }

    public function isMonthlyOrderLimitReached(?int $currentCount = null, ?CarbonInterface $now = null): bool
    {
        $limit = (int) $this->limit('monthly_orders_limit', 0);

        if ($limit < 1) {
            return false;
        }

        $currentCount = $currentCount ?? $this->currentMonthlyOrderCount($now);

        return $currentCount >= $limit;
    }

    public function isNearProductLimit(?int $currentCount = null, int $threshold = 80): bool
    {
        if ($this->isProductLimitReached($currentCount)) {
            return false;
        }

        return $this->productUsagePercentage($currentCount) >= $threshold;
    }

    public function isNearMonthlyOrderLimit(?int $currentCount = null, ?CarbonInterface $now = null, int $threshold = 80): bool
    {
        if ($this->isMonthlyOrderLimitReached($currentCount, $now)) {
            return false;
        }

        return $this->monthlyOrderUsagePercentage($currentCount, $now) >= $threshold;
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

    public function expiresAt(): ?CarbonInterface
    {
        return $this->parseDate(config('coremarket.license.expires_at'));
    }

    public function graceUntil(): ?CarbonInterface
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

    protected function featureAccess(): CoreMarketFeatureAccessService
    {
        return $this->featureAccess ??= app(CoreMarketFeatureAccessService::class);
    }

    protected function runtimeSnapshot(): CoreMarketRuntimeSnapshotService
    {
        return $this->runtimeSnapshot ??= app(CoreMarketRuntimeSnapshotService::class);
    }
}
