<?php

namespace App\Services;

use App\Models\BusinessSetting;
use App\Models\Currency;
use App\Models\Language;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CoreMarketCleanBaselineService
{
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
            'product_count' => DB::table('products')->count(),
            'order_count' => DB::table('orders')->count(),
            'upload_count' => DB::table('uploads')->count(),
            'remaining_branding_warnings' => $this->remainingBrandingWarnings(),
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
        $applied = [];

        foreach ($plan['settings'] as $setting) {
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

        Cache::forget('business_settings');
        Cache::forget('system_default_currency');

        return [
            'settings' => $applied,
            'target_currency' => $plan['target_currency'],
            'target_language' => $plan['target_language'],
        ];
    }

    protected function buildSettingsPreview(): array
    {
        $preview = collect();
        $targetCurrency = $this->targetCurrency();

        foreach ($this->settingDefaults($targetCurrency['id']) as $type => $targetValue) {
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
        $currency = Currency::query()->where('code', 'USD')->first();

        return [
            'code' => 'USD',
            'id' => $currency?->id,
            'exists' => $currency !== null,
        ];
    }

    protected function targetLanguage(): array
    {
        $language = Language::query()->where('code', 'en')->first();

        return [
            'code' => 'en',
            'id' => $language?->id,
            'exists' => $language !== null,
        ];
    }

    protected function remainingBrandingWarnings(): array
    {
        $warnings = [];

        foreach (config('coremarket.clean_baseline.legacy_terms', []) as $term) {
            $matches = DB::table('business_settings')
                ->where('value', 'like', '%' . $term . '%')
                ->count();

            if ($matches < 1) {
                continue;
            }

            $warnings[] = [
                'term' => $term,
                'business_settings' => $matches,
            ];
        }

        return $warnings;
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
}
