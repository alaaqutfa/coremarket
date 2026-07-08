<?php

namespace App\Services;

use App\Models\BusinessSetting;
use App\Models\Currency;
use App\Models\Language;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role as SpatieRole;

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
            'pages' => $this->buildPagePreview(),
            'page_translations' => $this->buildPageTranslationPreview(),
            'categories' => $this->buildCategoryPreview(),
            'translations' => $this->buildTranslationPreview(),
            'messages' => $this->buildMessagePreview(),
            'roles' => $this->buildRolePreview(),
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
        $appliedPages = $this->applyPageDefaults($plan['pages']);
        $appliedPageTranslations = $this->applyPageTranslationDefaults($plan['page_translations']);
        $appliedCategories = $this->applyCategoryDefaults($plan['categories']);
        $appliedTranslations = $this->applyTranslationDefaults($plan['translations']);
        $appliedMessages = $this->applyMessageDefaults($plan['messages']);
        $appliedRoles = $this->applyRoleDefaults($plan['roles']);

        Cache::forget('business_settings');
        Cache::forget('system_default_currency');

        return [
            'settings' => $applied,
            'shops' => $appliedShops,
            'pages' => $appliedPages,
            'page_translations' => $appliedPageTranslations,
            'categories' => $appliedCategories,
            'translations' => $appliedTranslations,
            'messages' => $appliedMessages,
            'roles' => $appliedRoles,
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

    protected function buildPagePreview(): array
    {
        if (! Schema::hasTable('pages')) {
            return [];
        }

        $defaults = config('coremarket.clean_baseline.page_defaults', []);
        $preview = [];

        foreach (DB::table('pages')->get() as $page) {
            foreach (['title', 'meta_title', 'meta_description', 'keywords'] as $field) {
                if (! property_exists($page, $field)) {
                    continue;
                }

                $currentValue = $page->{$field};
                $targetValue = $this->neutralizedText($currentValue, $field, $defaults[$field] ?? null, config('coremarket.clean_baseline.page_replacements', []));

                if ($targetValue === null || $targetValue === $currentValue) {
                    continue;
                }

                $preview[] = [
                    'id' => $page->id,
                    'field' => $field,
                    'current_value' => $currentValue,
                    'target_value' => $targetValue,
                ];
            }
        }

        return $preview;
    }

    protected function buildCategoryPreview(): array
    {
        if (! Schema::hasTable('categories')) {
            return [];
        }

        $preview = [];

        foreach (DB::table('categories')->get() as $category) {
            foreach (['name', 'slug', 'meta_title', 'meta_description'] as $field) {
                if (! property_exists($category, $field)) {
                    continue;
                }

                $currentValue = $category->{$field};
                $targetValue = $this->neutralizedText($currentValue, $field, null, config('coremarket.clean_baseline.category_replacements', []));

                if ($targetValue === null || $targetValue === $currentValue) {
                    continue;
                }

                $preview[] = [
                    'id' => $category->id,
                    'field' => $field,
                    'current_value' => $currentValue,
                    'target_value' => $targetValue,
                ];
            }
        }

        return $preview;
    }

    protected function buildPageTranslationPreview(): array
    {
        if (! Schema::hasTable('page_translations')) {
            return [];
        }

        $defaults = config('coremarket.clean_baseline.page_translation_defaults', []);
        $preview = [];

        foreach (DB::table('page_translations')->get() as $translation) {
            foreach (['title', 'content'] as $field) {
                if (! property_exists($translation, $field)) {
                    continue;
                }

                $currentValue = $translation->{$field};
                $targetValue = $this->neutralizedText(
                    $currentValue,
                    $field,
                    $defaults[$field] ?? null,
                    config('coremarket.clean_baseline.page_replacements', [])
                );

                if ($targetValue === null || $targetValue === $currentValue) {
                    continue;
                }

                $preview[] = [
                    'id' => $translation->id,
                    'lang' => $translation->lang ?? null,
                    'field' => $field,
                    'current_value' => $currentValue,
                    'target_value' => $targetValue,
                ];
            }
        }

        return $preview;
    }

    protected function buildTranslationPreview(): array
    {
        if (! Schema::hasTable('translations')) {
            return [];
        }

        $preview = [];
        $replacements = config('coremarket.clean_baseline.translation_replacements', []);

        foreach (DB::table('translations')->get() as $translation) {
            if (! property_exists($translation, 'lang_value') || ! is_string($translation->lang_value)) {
                continue;
            }

            $targetValue = $this->neutralizedTranslationValue($translation->lang_value, $replacements);

            if ($targetValue === null || $targetValue === $translation->lang_value) {
                continue;
            }

            $preview[] = [
                'id' => $translation->id,
                'lang' => $translation->lang ?? null,
                'lang_key' => $translation->lang_key ?? null,
                'current_value' => $translation->lang_value,
                'target_value' => $targetValue,
            ];
        }

        return $preview;
    }

    protected function buildMessagePreview(): array
    {
        if (! Schema::hasTable('messages')) {
            return [];
        }

        $preview = [];
        $replacements = config('coremarket.clean_baseline.message_replacements', []);

        foreach (DB::table('messages')->get() as $message) {
            if (! property_exists($message, 'message') || ! is_string($message->message)) {
                continue;
            }

            $targetValue = $this->neutralizedTranslationValue($message->message, $replacements);

            if ($targetValue === null || $targetValue === $message->message) {
                continue;
            }

            $preview[] = [
                'id' => $message->id,
                'current_value' => $message->message,
                'target_value' => $targetValue,
            ];
        }

        return $preview;
    }

    protected function buildRolePreview(): array
    {
        if (! Schema::hasTable('roles')) {
            return [];
        }

        $roleName = config('coremarket.access.store_admin_role', 'store_admin');
        $role = SpatieRole::query()
            ->where('name', $roleName)
            ->where('guard_name', 'web')
            ->first();

        return [[
            'name' => $roleName,
            'guard_name' => 'web',
            'exists' => $role !== null,
            'current_id' => $role?->id,
        ]];
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

    protected function applyPageDefaults(array $pages): array
    {
        $applied = [];

        foreach ($pages as $row) {
            $page = DB::table('pages')->where('id', $row['id'])->first();

            if (! $page) {
                continue;
            }

            $previous = $page->{$row['field']};
            DB::table('pages')
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

    protected function applyCategoryDefaults(array $categories): array
    {
        $applied = [];

        foreach ($categories as $row) {
            $category = DB::table('categories')->where('id', $row['id'])->first();

            if (! $category) {
                continue;
            }

            $previous = $category->{$row['field']};
            DB::table('categories')
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

    protected function applyPageTranslationDefaults(array $translations): array
    {
        $applied = [];

        foreach ($translations as $row) {
            $translation = DB::table('page_translations')->where('id', $row['id'])->first();

            if (! $translation) {
                continue;
            }

            $previous = $translation->{$row['field']};
            DB::table('page_translations')
                ->where('id', $row['id'])
                ->update([
                    $row['field'] => $row['target_value'],
                ]);

            $applied[] = [
                'id' => $row['id'],
                'lang' => $row['lang'] ?? null,
                'field' => $row['field'],
                'previous' => $previous,
                'value' => $row['target_value'],
                'status' => 'updated',
            ];
        }

        return $applied;
    }

    protected function applyTranslationDefaults(array $translations): array
    {
        $applied = [];

        foreach ($translations as $row) {
            $translation = DB::table('translations')->where('id', $row['id'])->first();

            if (! $translation) {
                continue;
            }

            $previous = $translation->lang_value;
            DB::table('translations')
                ->where('id', $row['id'])
                ->update([
                    'lang_value' => $row['target_value'],
                ]);

            $applied[] = [
                'id' => $row['id'],
                'lang' => $row['lang'] ?? null,
                'lang_key' => $row['lang_key'] ?? null,
                'previous' => $previous,
                'value' => $row['target_value'],
                'status' => 'updated',
            ];
        }

        return $applied;
    }

    protected function applyMessageDefaults(array $messages): array
    {
        $applied = [];

        foreach ($messages as $row) {
            $message = DB::table('messages')->where('id', $row['id'])->first();

            if (! $message) {
                continue;
            }

            $previous = $message->message;
            DB::table('messages')
                ->where('id', $row['id'])
                ->update([
                    'message' => $row['target_value'],
                ]);

            $applied[] = [
                'id' => $row['id'],
                'previous' => $previous,
                'value' => $row['target_value'],
                'status' => 'updated',
            ];
        }

        return $applied;
    }

    protected function applyRoleDefaults(array $roles): array
    {
        $applied = [];

        foreach ($roles as $row) {
            $role = SpatieRole::query()
                ->where('name', $row['name'])
                ->where('guard_name', $row['guard_name'])
                ->first();

            if ($role) {
                $applied[] = [
                    'name' => $row['name'],
                    'guard_name' => $row['guard_name'],
                    'status' => 'existing',
                    'role_id' => $role->id,
                ];

                continue;
            }

            $role = SpatieRole::query()->create([
                'name' => $row['name'],
                'guard_name' => $row['guard_name'],
            ]);

            $applied[] = [
                'name' => $row['name'],
                'guard_name' => $row['guard_name'],
                'status' => 'created',
                'role_id' => $role->id,
            ];
        }

        return $applied;
    }

    protected function neutralizedText($currentValue, string $field, $defaultValue, array $replacements): ?string
    {
        if (! is_string($currentValue) || trim($currentValue) === '') {
            return null;
        }

        if (! $this->containsLegacyTerm($currentValue)) {
            return null;
        }

        $target = $currentValue;

        foreach ($replacements as $search => $replace) {
            $target = str_replace($search, $replace, $target);
        }

        $target = preg_replace('/\s+/', ' ', (string) $target);
        $target = trim((string) $target, " \t\n\r\0\x0B|,-");

        if ($field === 'keywords') {
            return $defaultValue ?? $target;
        }

        if ($field === 'title' && $defaultValue !== null && $this->containsLegacyTerm($currentValue)) {
            return $defaultValue;
        }

        return $target === '' ? $defaultValue : $target;
    }

    protected function neutralizedTranslationValue(string $currentValue, array $replacements): ?string
    {
        if (trim($currentValue) === '' || ! $this->containsLegacyTerm($currentValue)) {
            return null;
        }

        $target = $currentValue;

        foreach ($replacements as $search => $replace) {
            $target = str_replace($search, $replace, $target);
        }

        $target = preg_replace('/\s+/', ' ', (string) $target);
        $target = trim((string) $target);

        return $target === '' ? null : $target;
    }

    protected function containsLegacyTerm(string $value): bool
    {
        foreach (config('coremarket.clean_baseline.legacy_terms', []) as $term) {
            if ($term !== '' && stripos($value, $term) !== false) {
                return true;
            }
        }

        return false;
    }

    protected function readiness(): CoreMarketBaselineReadinessService
    {
        return $this->readinessService ??= app(CoreMarketBaselineReadinessService::class);
    }
}
