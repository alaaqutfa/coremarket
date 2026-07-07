<?php

namespace App\Services;

class CoreMarketFeatureService
{
    protected ?CoreMarketFeatureAccessService $access = null;

    public function planCode(?string $default = null): ?string
    {
        return $this->access()->appliedPlan($default);
    }

    public function storeMode(?string $default = null): string
    {
        return $this->access()->storeMode($default);
    }

    public function enabled(string $feature, bool $default = false): bool
    {
        return $this->access()->enabled($feature, $default);
    }

    public function value(string $feature, $default = null)
    {
        return $this->access()->value($feature, $default);
    }

    public function limit(string $limit, $default = null)
    {
        return $this->access()->limit($limit, $default);
    }

    protected function access(): CoreMarketFeatureAccessService
    {
        return $this->access ??= app(CoreMarketFeatureAccessService::class);
    }
}
