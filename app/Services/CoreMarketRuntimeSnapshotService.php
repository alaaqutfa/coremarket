<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CoreMarketRuntimeSnapshotService
{
    protected ?array $runtimeDiagnostics = null;

    public function preview(array $payload): array
    {
        return $this->normalizePayload($payload);
    }

    public function apply(array $payload): array
    {
        $diagnostics = $this->ensureSettingsTableAvailable();
        $connectionName = $diagnostics['runtime_connection_name'];

        $normalized = $this->normalizePayload($payload);

        $applied = [];

        foreach ($this->persistedSettings($normalized) as $key => $value) {
            $this->writeSetting($connectionName, $key, $value);
            $applied[$key] = $value;
        }

        foreach ($this->legacyRuntimeSettingMap($normalized['features']) as $key => $value) {
            $this->writeSetting($connectionName, $key, (string) $value);
            $applied[$key] = (string) $value;
        }

        Cache::forget('business_settings');

        return [
            'status' => 'applied',
            'applied_settings' => array_keys($applied),
            'runtime' => $normalized,
        ];
    }

    public function hasAppliedSnapshot(): bool
    {
        return $this->settingValue($this->key('status')) !== null
            || $this->settingValue($this->key('applied_plan')) !== null;
    }

    public function persistedPlanCode(): ?string
    {
        return $this->settingValue($this->key('applied_plan'));
    }

    public function persistedStoreMode(): ?string
    {
        return $this->settingValue($this->key('store_mode'));
    }

    public function persistedStatus(): ?string
    {
        return $this->settingValue($this->key('status'));
    }

    public function persistedFeatures(): array
    {
        return $this->decodeJsonSetting($this->key('features'));
    }

    public function persistedLimits(): array
    {
        return $this->decodeJsonSetting($this->key('limits'));
    }

    public function persistedStoreMetadata(): array
    {
        return $this->decodeJsonSetting($this->key('store_metadata'));
    }

    public function persistedSupportMetadata(): array
    {
        return $this->decodeJsonSetting($this->key('support_metadata'));
    }

    public function normalizedPersistedSnapshot(): array
    {
        return $this->normalizePayload([
            'status' => $this->persistedStatus(),
            'applied_plan' => $this->persistedPlanCode(),
            'store_mode' => $this->persistedStoreMode(),
            'features' => $this->persistedFeatures(),
            'limits' => $this->persistedLimits(),
            'store' => $this->persistedStoreMetadata(),
            'support' => $this->persistedSupportMetadata(),
        ]);
    }

    public function key(string $name): string
    {
        return config("coremarket.runtime_snapshot.setting_keys.{$name}");
    }

    protected function normalizePayload(array $payload): array
    {
        $featureAccess = app(CoreMarketFeatureAccessService::class);

        $appliedPlan = $featureAccess->resolveAppliedPlan(
            $payload['applied_plan'] ?? $payload['applied_plan_code'] ?? null
        );
        $storeMode = $featureAccess->resolveStoreMode(
            $appliedPlan,
            $payload['store_mode'] ?? null
        );

        $matrix = $featureAccess->matrixFor($appliedPlan, $storeMode, false);

        $features = $matrix['features'];
        foreach ($featureAccess->featureKeys() as $featureKey) {
            if (array_key_exists($featureKey, $payload['features'] ?? [])) {
                $features[$featureKey] = filter_var($payload['features'][$featureKey], FILTER_VALIDATE_BOOL);
            }
        }

        $limits = $matrix['limits'];
        foreach ($featureAccess->limitKeys() as $limitKey) {
            if (array_key_exists($limitKey, $payload['limits'] ?? [])) {
                $value = $payload['limits'][$limitKey];
                $limits[$limitKey] = $value === null || $value === '' ? null : (int) $value;
            }
        }

        return [
            'status' => $this->normalizeStatus($payload['status'] ?? null),
            'applied_plan' => $appliedPlan,
            'store_mode' => $storeMode,
            'features' => $features,
            'limits' => $limits,
            'store' => $this->normalizeMetadata(
                $payload['store'] ?? [],
                config('coremarket.runtime_snapshot.allowed_store_metadata', [])
            ),
            'support' => $this->normalizeMetadata(
                $payload['support'] ?? [],
                config('coremarket.runtime_snapshot.allowed_support_metadata', [])
            ),
        ];
    }

    protected function persistedSettings(array $normalized): array
    {
        return [
            $this->key('status') => $normalized['status'],
            $this->key('applied_plan') => $normalized['applied_plan'],
            $this->key('store_mode') => $normalized['store_mode'],
            $this->key('features') => json_encode($normalized['features'], JSON_UNESCAPED_SLASHES),
            $this->key('limits') => json_encode($normalized['limits'], JSON_UNESCAPED_SLASHES),
            $this->key('store_metadata') => json_encode($normalized['store'], JSON_UNESCAPED_SLASHES),
            $this->key('support_metadata') => json_encode($normalized['support'], JSON_UNESCAPED_SLASHES),
        ];
    }

    protected function legacyRuntimeSettingMap(array $features): array
    {
        $mapped = [];

        foreach (config('coremarket.instance_setup.runtime_feature_to_legacy_setting_map', []) as $feature => $settingKey) {
            if (! array_key_exists($feature, $features)) {
                continue;
            }

            $mapped[$settingKey] = $features[$feature] ? 1 : 0;
        }

        return $mapped;
    }

    protected function normalizeMetadata(array $values, array $allowedKeys): array
    {
        return collect($allowedKeys)
            ->mapWithKeys(function (string $key) use ($values) {
                $value = Arr::get($values, $key);

                return [$key => is_string($value) ? trim($value) : $value];
            })
            ->reject(fn ($value) => $value === null || $value === '')
            ->all();
    }

    protected function normalizeStatus(?string $status): string
    {
        $normalized = strtolower(trim((string) $status));
        $allowed = ['active', 'inactive', 'suspended', 'expired'];

        return in_array($normalized, $allowed, true) ? $normalized : 'active';
    }

    protected function settingValue(?string $key): ?string
    {
        if (! $key) {
            return null;
        }

        try {
            $diagnostics = $this->runtimeDiagnostics();

            if (
                ! ($diagnostics['has_business_settings_table'] ?? false)
                || ($diagnostics['forbidden_database_detected'] ?? false)
                || empty($diagnostics['runtime_database_name'])
            ) {
                return null;
            }

            return $this->settingsQuery($diagnostics['runtime_connection_name'])
                ->where('type', $key)
                ->whereNull('lang')
                ->value('value');
        } catch (\Throwable $exception) {
            return null;
        }
    }

    protected function decodeJsonSetting(?string $key): array
    {
        $value = $this->settingValue($key);
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    protected function hasSettingsTable(): bool
    {
        return (bool) ($this->runtimeDiagnostics()['has_business_settings_table'] ?? false);
    }

    protected function ensureSettingsTableAvailable(): array
    {
        return $this->runtimeResolver()->requireWritableRuntimeConnection();
    }

    public function storageDiagnostics(): array
    {
        return $this->runtimeDiagnostics();
    }

    protected function runtimeDiagnostics(): array
    {
        return $this->runtimeDiagnostics ??= $this->runtimeResolver()->resolve();
    }

    protected function settingsQuery(string $connectionName)
    {
        return DB::connection($connectionName)->table('business_settings');
    }

    protected function writeSetting(string $connectionName, string $key, ?string $value): void
    {
        $existing = $this->settingsQuery($connectionName)
            ->where('type', $key)
            ->whereNull('lang')
            ->first();

        $timestamp = now();

        if ($existing) {
            $this->settingsQuery($connectionName)
                ->where('id', $existing->id)
                ->update([
                    'value' => $value,
                    'updated_at' => $timestamp,
                ]);

            return;
        }

        $this->settingsQuery($connectionName)->insert([
            'type' => $key,
            'lang' => null,
            'value' => $value,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    protected function runtimeResolver(): CoreMarketRuntimeDatabaseResolver
    {
        return app(CoreMarketRuntimeDatabaseResolver::class);
    }
}
