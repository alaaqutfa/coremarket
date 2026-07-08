<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CoreMarketBaselineReadinessService
{
    public function buildReport(): array
    {
        $tableCounts = $this->buildTableCounts();
        $requiredSettings = $this->buildRequiredSettingsStatus();
        $oldBrandingWarnings = $this->buildOldBrandingWarnings();
        $statusFlags = $this->buildStatusFlags();
        $schemaDrift = $this->buildSchemaDriftStatus();
        $baselineDataCounts = $this->buildBaselineDataCounts();

        return [
            'table_counts' => $tableCounts,
            'required_settings' => $requiredSettings,
            'old_branding_warnings' => $oldBrandingWarnings,
            'status_flags' => $statusFlags,
            'schema_drift' => $schemaDrift,
            'baseline_data_counts' => $baselineDataCounts,
            'summary' => $this->buildSummary($requiredSettings, $oldBrandingWarnings, $statusFlags, $schemaDrift, $baselineDataCounts),
        ];
    }

    protected function buildTableCounts(): array
    {
        $tables = [
            'users',
            'staff',
            'roles',
            'permissions',
            'business_settings',
            'currencies',
            'languages',
            'countries',
            'products',
            'categories',
            'brands',
            'uploads',
            'orders',
            'order_details',
            'sellers',
            'shops',
            'carts',
            'wishlists',
        ];

        $counts = [];

        foreach ($tables as $table) {
            $counts[] = [
                'table' => $table,
                'count' => Schema::hasTable($table) ? DB::table($table)->count() : null,
                'status' => Schema::hasTable($table) ? 'PASS' : 'FAIL',
            ];
        }

        return $counts;
    }

    protected function buildRequiredSettingsStatus(): array
    {
        $requiredKeys = [
            'website_name',
            'site_name',
            'site_motto',
            'meta_title',
            'meta_description',
            'contact_phone',
            'contact_email',
            'contact_address',
            'system_default_currency',
            'timezone',
            'cash_payment',
            'vendor_system_activation',
            'wallet_system',
            'show_website_popup',
            'show_cookies_agreement',
        ];

        $rows = collect();

        foreach ($requiredKeys as $key) {
            $setting = DB::table('business_settings')
                ->where('type', $key)
                ->whereNull('lang')
                ->first();

            $value = $setting->value ?? null;
            $present = $setting !== null;
            $isBlank = $present && trim((string) $value) === '';

            $status = $present && ! $isBlank ? 'PASS' : 'WARN';

            $rows->push([
                'key' => $key,
                'status' => $status,
                'value' => $this->normalizePreviewValue($value),
                'note' => $present ? ($isBlank ? 'blank value' : 'present') : 'missing',
            ]);
        }

        return $rows->all();
    }

    protected function buildOldBrandingWarnings(): array
    {
        $terms = [
            'Coin Market',
            'Coin Markert',
            'coin-market',
            'demo.coin-market.store',
            'Group Coin',
            'Syrian Souq',
            'syriansouq',
            'syrian_souq',
            'الشاهين',
            'shaheen',
            'activeitzone',
            'codecanyon',
            'Active eCommerce',
            'http://localhost/syrian-souq',
            'https://syriansouq.com',
        ];

        $warnings = collect();

        foreach ($terms as $term) {
            $businessSettingsCount = DB::table('business_settings')
                ->where('value', 'like', '%' . $term . '%')
                ->count();

            $shopsCount = Schema::hasTable('shops')
                ? DB::table('shops')
                    ->where(function ($query) use ($term) {
                        $query->where('name', 'like', '%' . $term . '%')
                            ->orWhere('slug', 'like', '%' . $term . '%')
                            ->orWhere('meta_title', 'like', '%' . $term . '%');
                    })
                    ->count()
                : 0;

            $productsCount = Schema::hasTable('products')
                ? DB::table('products')
                    ->where(function ($query) use ($term) {
                        $query->where('name', 'like', '%' . $term . '%')
                            ->orWhere('meta_title', 'like', '%' . $term . '%')
                            ->orWhere('meta_description', 'like', '%' . $term . '%');
                    })
                    ->count()
                : 0;

            $total = $businessSettingsCount + $shopsCount + $productsCount;

            if ($total === 0) {
                continue;
            }

            $warnings->push([
                'term' => $term,
                'status' => 'WARN',
                'business_settings' => $businessSettingsCount,
                'shops' => $shopsCount,
                'products' => $productsCount,
            ]);
        }

        return $warnings->all();
    }

    protected function buildStatusFlags(): array
    {
        return [
            $this->buildBinarySettingStatus('vendor_system_activation', 'Vendor mode should be disabled for the managed baseline', '0'),
            $this->buildBinarySettingStatus('wallet_system', 'Wallet should be disabled for the managed baseline', '0'),
            $this->buildBinarySettingStatus('show_website_popup', 'Marketing popup should be disabled for the managed baseline', '0'),
            $this->buildCashPaymentStatus(),
        ];
    }

    protected function buildSchemaDriftStatus(): array
    {
        $liveTables = count(DB::select('SHOW TABLES'));
        $trackedMigrations = count(glob(database_path('migrations/*.php')));
        $migrationCreates = count(glob(database_path('migrations/*.php')));

        $status = $liveTables > $migrationCreates ? 'FAIL' : 'PASS';

        return [
            'status' => $status,
            'live_tables' => $liveTables,
            'tracked_migration_files' => $trackedMigrations,
            'message' => $status === 'FAIL'
                ? 'Tracked migrations do not describe the full working schema.'
                : 'Tracked migrations appear to cover the working schema.',
        ];
    }

    protected function buildBaselineDataCounts(): array
    {
        $tables = [
            'products',
            'uploads',
            'orders',
        ];

        return collect($tables)->map(function (string $table) {
            return [
                'table' => $table,
                'count' => Schema::hasTable($table) ? DB::table($table)->count() : null,
                'status' => Schema::hasTable($table) ? 'INFO' : 'FAIL',
            ];
        })->all();
    }

    protected function buildSummary(
        array $requiredSettings,
        array $oldBrandingWarnings,
        array $statusFlags,
        array $schemaDrift,
        array $baselineDataCounts
    ): array {
        $hasMissingSettings = collect($requiredSettings)->contains(fn (array $row) => $row['status'] !== 'PASS');
        $hasStatusWarnings = collect($statusFlags)->contains(fn (array $row) => $row['status'] !== 'PASS');
        $hasBrandingWarnings = ! empty($oldBrandingWarnings);
        $hasRuntimeData = collect($baselineDataCounts)->contains(fn (array $row) => ($row['count'] ?? 0) > 0);

        return [
            [
                'status' => $schemaDrift['status'],
                'label' => 'Schema drift',
                'message' => $schemaDrift['message'],
            ],
            [
                'status' => $hasMissingSettings ? 'WARN' : 'PASS',
                'label' => 'Required baseline settings',
                'message' => $hasMissingSettings
                    ? 'Some required baseline settings are missing or blank.'
                    : 'Required baseline settings are present.',
            ],
            [
                'status' => $hasBrandingWarnings ? 'WARN' : 'PASS',
                'label' => 'Legacy branding scan',
                'message' => $hasBrandingWarnings
                    ? 'Legacy branding or demo references are still present.'
                    : 'No legacy branding references were detected in audited surfaces.',
            ],
            [
                'status' => $hasStatusWarnings ? 'WARN' : 'PASS',
                'label' => 'Managed baseline feature flags',
                'message' => $hasStatusWarnings
                    ? 'One or more managed baseline flags need cleanup.'
                    : 'Managed baseline flags match the intended starter baseline.',
            ],
            [
                'status' => $hasRuntimeData ? 'WARN' : 'PASS',
                'label' => 'Client/demo runtime data',
                'message' => $hasRuntimeData
                    ? 'Products, uploads, or orders still exist and may need a later reset workflow.'
                    : 'Products, uploads, and orders are empty in the current baseline.',
            ],
        ];
    }

    protected function buildBinarySettingStatus(string $key, string $message, string $expectedValue): array
    {
        $setting = DB::table('business_settings')
            ->where('type', $key)
            ->whereNull('lang')
            ->first();

        $value = (string) ($setting->value ?? '');
        $status = $value === $expectedValue ? 'PASS' : 'WARN';

        return [
            'key' => $key,
            'status' => $status,
            'value' => $this->normalizePreviewValue($setting->value ?? null),
            'message' => $message,
        ];
    }

    protected function buildCashPaymentStatus(): array
    {
        $setting = DB::table('business_settings')
            ->where('type', 'cash_payment')
            ->whereNull('lang')
            ->first();

        $value = (string) ($setting->value ?? '');
        $status = $value === '1' ? 'PASS' : 'WARN';

        return [
            'key' => 'cash_payment',
            'status' => $status,
            'value' => $this->normalizePreviewValue($setting->value ?? null),
            'message' => 'Cash/manual payment should remain enabled for the starter baseline.',
        ];
    }

    protected function normalizePreviewValue($value): string
    {
        if ($value === null) {
            return '[missing]';
        }

        $normalized = preg_replace('/\s+/', ' ', (string) $value);
        $normalized = trim($normalized);

        if ($normalized === '') {
            return '[blank]';
        }

        return mb_strlen($normalized) > 70
            ? mb_substr($normalized, 0, 67) . '...'
            : $normalized;
    }
}
