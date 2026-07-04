<?php

namespace App\Services;

use App\Models\BusinessSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CoreMarketStorefrontCleanupService
{
    public function buildPlan(array $options = []): array
    {
        return [
            'dry_run' => ! (bool) ($options['apply'] ?? false),
            'apply_requested' => (bool) ($options['apply'] ?? false),
            'confirmed' => (bool) ($options['confirm_storefront_cleanup'] ?? false),
            'settings' => $this->buildSettingsPreview(),
            'notes' => config('coremarket.storefront_cleanup.notes', []),
        ];
    }

    public function validateApplyRequirements(array $plan): array
    {
        $errors = [];

        if (! $plan['apply_requested']) {
            return $errors;
        }

        if (! $plan['confirmed']) {
            $errors[] = 'Apply mode requires --confirm-storefront-cleanup.';
        }

        return $errors;
    }

    public function applyCleanup(array $settings): array
    {
        $applied = [];

        foreach ($settings as $setting) {
            $query = DB::table('business_settings')->where('type', $setting['type']);

            if ($setting['lang'] === null) {
                $query->whereNull('lang');
            } else {
                $query->where('lang', $setting['lang']);
            }

            $existing = $query->first();
            $wasExisting = $existing !== null;
            $previousValue = $existing->value ?? null;

            if ($wasExisting) {
                $query->update(['value' => $setting['target_value']]);
            } else {
                DB::table('business_settings')->insert([
                    'type' => $setting['type'],
                    'lang' => $setting['lang'],
                    'value' => $setting['target_value'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $applied[] = [
                'type' => $setting['type'],
                'lang' => $setting['lang'],
                'previous' => $previousValue,
                'value' => $setting['target_value'],
                'status' => $wasExisting ? 'updated' : 'created',
            ];
        }

        Cache::forget('business_settings');

        return $applied;
    }

    protected function buildSettingsPreview(): array
    {
        $preview = collect();

        foreach (config('coremarket.storefront_cleanup.setting_defaults', []) as $type => $targetValue) {
            $setting = BusinessSetting::query()
                ->where('type', $type)
                ->whereNull('lang')
                ->first();

            $preview->push($this->formatPreviewRow($type, null, $setting?->value, $targetValue));
        }

        foreach (config('coremarket.storefront_cleanup.localized_setting_defaults', []) as $type => $targetValue) {
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
