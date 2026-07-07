<?php

namespace App\Services;

use Illuminate\Support\Arr;

class CoreMarketFeatureAccessService
{
    public function appliedPlan(?string $default = null): string
    {
        $default = $default ?? config('coremarket.runtime.default_applied_plan', 'starter');

        foreach ([
            config('coremarket.runtime.applied_plan_code'),
            config('coremarket.license.applied_plan_code'),
            config('coremarket.license.plan_code'),
            config('coremarket.plan.code'),
            $default,
        ] as $candidate) {
            $normalized = $this->normalizePlanCode($candidate);

            if ($normalized !== null) {
                return $normalized;
            }
        }

        return 'starter';
    }

    public function storeMode(?string $default = null): string
    {
        return $this->resolveStoreMode($this->appliedPlan(), null, $default);
    }

    public function resolveAppliedPlan(?string $planCode = null, ?string $default = null): string
    {
        $default = $default ?? config('coremarket.runtime.default_applied_plan', 'starter');

        foreach ([$planCode, $default] as $candidate) {
            $normalized = $this->normalizePlanCode($candidate);

            if ($normalized !== null) {
                return $normalized;
            }
        }

        return 'starter';
    }

    public function resolveStoreMode(string $appliedPlan, ?string $storeMode = null, ?string $default = null): string
    {
        foreach ([
            $storeMode,
            config('coremarket.runtime.store_mode'),
            config('coremarket.license.store_mode'),
            config('coremarket.runtime.plans.' . $appliedPlan . '.default_store_mode'),
            $default,
        ] as $candidate) {
            $normalized = $this->normalizeStoreMode($candidate);

            if ($normalized !== null) {
                return $normalized;
            }
        }

        return config('coremarket.runtime.default_store_mode', 'single_store');
    }

    public function matrixFor(?string $planCode = null, ?string $storeMode = null): array
    {
        $resolvedPlan = $this->resolveAppliedPlan($planCode);
        $resolvedStoreMode = $this->resolveStoreMode($resolvedPlan, $storeMode);

        return [
            'applied_plan' => $resolvedPlan,
            'store_mode' => $resolvedStoreMode,
            'features' => collect($this->featureKeys())
                ->mapWithKeys(fn (string $key) => [$key => $this->valueFor($key, $resolvedPlan, $resolvedStoreMode)])
                ->all(),
            'limits' => collect($this->limitKeys())
                ->mapWithKeys(fn (string $key) => [$key => $this->limitFor($key, $resolvedPlan, $resolvedStoreMode)])
                ->all(),
        ];
    }

    public function enabled(string $feature, bool $default = false): bool
    {
        return (bool) $this->value($feature, $default);
    }

    public function value(string $feature, $default = null)
    {
        return $this->valueFor($feature, $this->appliedPlan(), $this->storeMode(), $default);
    }

    public function valueFor(string $feature, string $appliedPlan, string $storeMode, $default = null)
    {
        $explicitOriginalValue = config("coremarket.features.{$feature}");
        if ($explicitOriginalValue !== null) {
            return $explicitOriginalValue;
        }

        $featureKey = $this->normalizeFeatureKey($feature);

        foreach ([
            config("coremarket.features.{$featureKey}"),
            Arr::get(config('coremarket.license.feature_overrides', []), $featureKey),
        ] as $candidate) {
            if ($candidate !== null) {
                return $candidate;
            }
        }

        $planValue = Arr::get(config('coremarket.runtime.plans.' . $appliedPlan . '.features', []), $featureKey);
        $modeValue = Arr::get(config('coremarket.runtime.store_modes.' . $storeMode . '.feature_overrides', []), $featureKey);
        $defaultValue = Arr::get(config('coremarket.runtime.feature_definitions', []), $featureKey . '.default');

        $resolvedValue = $planValue ?? $defaultValue;

        if ($modeValue === false) {
            return false;
        }

        if ($modeValue === true) {
            return (bool) $resolvedValue;
        }

        if ($resolvedValue !== null) {
            return $resolvedValue;
        }

        return $default;
    }

    public function limit(string $limit, $default = null)
    {
        return $this->limitFor($limit, $this->appliedPlan(), $this->storeMode(), $default);
    }

    public function limitFor(string $limit, string $appliedPlan, string $storeMode, $default = null)
    {
        foreach ([
            Arr::get(config('coremarket.license.limit_overrides', []), $limit),
            Arr::get(config('coremarket.runtime.store_modes.' . $storeMode . '.limit_overrides', []), $limit),
            Arr::get(config('coremarket.runtime.plans.' . $appliedPlan . '.limits', []), $limit),
            config("coremarket.limits.{$limit}"),
            Arr::get(config('coremarket.runtime.limit_definitions', []), $limit . '.default'),
        ] as $candidate) {
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return $default;
    }

    public function featureKeys(): array
    {
        return array_keys(config('coremarket.runtime.feature_definitions', []));
    }

    public function limitKeys(): array
    {
        return array_keys(config('coremarket.runtime.limit_definitions', []));
    }

    public function normalizePlanCode(?string $code): ?string
    {
        if ($code === null || trim($code) === '') {
            return null;
        }

        $normalized = strtolower(trim($code));

        return config('coremarket.runtime.plan_aliases.' . $normalized, $normalized);
    }

    public function normalizeStoreMode(?string $mode): ?string
    {
        if ($mode === null || trim($mode) === '') {
            return null;
        }

        $normalized = strtolower(trim($mode));

        return config('coremarket.runtime.store_mode_aliases.' . $normalized, $normalized);
    }

    protected function normalizeFeatureKey(string $feature): string
    {
        return config('coremarket.runtime.feature_aliases.' . $feature, $feature);
    }
}
