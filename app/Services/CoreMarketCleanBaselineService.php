<?php

namespace App\Services;

use App\Models\BusinessSetting;
use App\Models\Currency;
use App\Models\Language;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CoreMarketCleanBaselineService
{
    protected ?CoreMarketBaselineReadinessService $readinessService = null;

    public function buildPlan(array $options = []): array
    {
        return [
            'dry_run' => ! (bool) ($options['apply'] ?? false),
            'apply_requested' => (bool) ($options['apply'] ?? false),
            'confirmed' => (bool) ($options['confirm_clean_baseline'] ?? false),
            'database' => DB::connection()->getDatabaseName(),
            'table_count' => count(DB::select('SHOW TABLES')),
            'target_language' => $this->targetLanguage(),
            'target_currency' => $this->targetCurrency(),
            'settings' => $this->buildSettingsPreview(),
            'shops' => $this->buildShopPreview(),
            'product_count' => Schema::hasTable('products') ? DB::table('products')->count() : 0,
            'order_count' => Schema::hasTable('orders') ? DB::table('orders')->count() : 0,
            'upload_count' => Schema::hasTable('uploads') ? DB::table('uploads')->count() : 0,
            'inventory' => $this->readiness()->baselineInventoryCounts(),
            'remaining_branding_warnings' => $this->readiness()->legacyBrandingFindings(),
            'notes' => config('coremarket.clean_baseline.notes', []),
        ];
    }

    public function validateApplyRequirements(array $plan): array
    {
        $errors = [];

        if (! $plan['apply_requested']) {
            return $errors;
        }

        if (! $plan['confirmed']) {
            $errors[] = 'Apply mode requires --confirm-clean-baseline.';
        }

        if (! $plan['target_currency']['exists']) {
            $errors[] = 'USD currency is not available in the current baseline database.';
        }

        if (! $plan['target_language']['exists']) {
            $errors[] = 'English language is not available in the current baseline database.';
        }

        return $errors;
    }

    public function applyPlan(array $plan): array
    {
        $applied = $this->applyBusinessSettings($plan['settings']);
        $appliedShops = $this->applyShopDefaults($plan['shops']);

        Cache::forget('business_settings');
        Cache::forget('system_default_currency');

        return [
            'settings' => $applied,
            'shops' => $appliedShops,
            'target_currency' => $plan['target_currency'],
            'target_language' => $plan['target_language'],
        ];
    }

    protected function buildSettingsPreview(): array
    {
        $preview = collect();
        $targetCurrency = $this->targetCurrency();

        foreach ($this->settingDefaults($targetCurrency['id']) as $type => $targetValue) {
            if (! Schema::hasTable('business_settings')) {
                $preview->push($this->formatPreviewRow($type, null, null, $targetValue));
                continue;
            }

            $rows = BusinessSetting::query()
                ->where('type', $type)
                ->orderByRaw('lang is null desc')
                ->get();

            if ($rows->isEmpty()) {
                $preview->push($this->formatPreviewRow($type, null, null, $targetValue));
                continue;
            }

            $rows->each(function (BusinessSetting $row) use ($preview, $targetValue) {
                $preview->push($this->formatPreviewRow($row->type, $row->lang, $row->value, $targetValue));
            });
        }

        return $preview
            ->unique(fn (array $row) => $row['type'] . '|' . ($row['lang'] ?? 'null'))
            ->values()
            ->all();
    }

    protected function settingDefaults(?int $currencyId): array
    {
        $defaults = config('coremarket.clean_baseline.setting_defaults', []);

        if ($currencyId !== null) {
            $defaults['system_default_currency'] = (string) $currencyId;
            $defaults['home_default_currency'] = (string) $currencyId;
        }

        return $defaults;
    }

    protected function targetCurrency(): array
    {
        $currency = Schema::hasTable('currencies')
            ? Currency::query()->where('code', 'USD')->first()
            : null;

        return [
            'code' => 'USD',
            'id' => $currency?->id,
            'exists' => $currency !== null,
        ];
    }

    protected function targetLanguage(): array
    {
        $language = Schema::hasTable('languages')
            ? Language::query()->where('code', 'en')->first()
            : null;

        return [
            'code' => 'en',
            'id' => $language?->id,
            'exists' => $language !== null,
        ];
    }

    protected function buildShopPreview(): array
    {
        if (! Schema::hasTable('shops')) {
            return [];
        }

        $defaults = config('coremarket.clean_baseline.shop_defaults', []);
        $preview = [];

        foreach (DB::table('shops')->get() as $shop) {
            foreach ($defaults as $field => $targetValue) {
                if (! property_exists($shop, $field)) {
                    continue;
                }

                $preview[] = [
                    'id' => $shop->id,
                    'field' => $field,
                    'current_value' => $shop->{$field},
                    'target_value' => $targetValue,
                ];
            }
        }

        return $preview;
    }

    protected function formatPreviewRow(string $type, ?string $lang, $currentValue, $targetValue): array
    {
        return [
            'type' => $type,
            'lang' => $lang,
            'current_value' => $currentValue,
            'target_value' => $targetValue,
        ];
    }

    protected function applyBusinessSettings(array $settings): array
    {
        $applied = [];

        foreach ($settings as $setting) {
            $query = DB::table('business_settings')->where('type', $setting['type']);

            if ($setting['lang'] === null) {
                $query->whereNull('lang');
            } else {
                $query->where('lang', $setting['lang']);
            }

            $existingRows = $query->get();
            $previousValue = $existingRows->first()->value ?? null;

            if ($existingRows->isEmpty()) {
                DB::table('business_settings')->insert([
                    'type' => $setting['type'],
                    'lang' => $setting['lang'],
                    'value' => $setting['target_value'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $status = 'created';
            } else {
                $query->update([
                    'value' => $setting['target_value'],
                    'updated_at' => now(),
                ]);

                $status = 'updated';
            }

            $applied[] = [
                'type' => $setting['type'],
                'lang' => $setting['lang'],
                'previous' => $previousValue,
                'value' => $setting['target_value'],
                'status' => $status,
            ];
        }

        return $applied;
    }

    protected function applyShopDefaults(array $shops): array
    {
        $applied = [];

        foreach ($shops as $row) {
            $shop = DB::table('shops')->where('id', $row['id'])->first();

            if (! $shop) {
                continue;
            }

            $previous = $shop->{$row['field']};
            DB::table('shops')
                ->where('id', $row['id'])
                ->update([
                    $row['field'] => $row['target_value'],
                    'updated_at' => now(),
                ]);

            $applied[] = [
                'id' => $row['id'],
                'field' => $row['field'],
                'previous' => $previous,
                'value' => $row['target_value'],
                'status' => 'updated',
            ];
        }

        return $applied;
    }

    protected function readiness(): CoreMarketBaselineReadinessService
    {
        return $this->readinessService ??= app(CoreMarketBaselineReadinessService::class);
    }
}
