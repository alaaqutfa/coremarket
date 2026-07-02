<?php

namespace App\Services;

class CoreMarketFeatureService
{
    public function planCode(?string $default = null): ?string
    {
        return config('coremarket.plan.code', $default);
    }

    public function enabled(string $feature, bool $default = false): bool
    {
        return (bool) config("coremarket.features.{$feature}", $default);
    }

    public function value(string $feature, $default = null)
    {
        return config("coremarket.features.{$feature}", $default);
    }

    public function limit(string $limit, $default = null)
    {
        return config("coremarket.limits.{$limit}", $default);
    }
}
