<?php

namespace App\Services;

use App\Models\BusinessSetting;
use Illuminate\Support\Facades\Cache;

class CoreMarketInstanceSetupService
{
    public function buildPlan(string $instanceId, array $options = []): array
    {
        $planCode = $options['plan'] ?? config('coremarket.instance_setup.default_plan', 'starter');
        $storeName = $options['store_name'] ?? null;
        $domain = $options['domain'] ?? null;
        $adminName = $options['admin_name'] ?? null;
        $adminEmail = $options['admin_email'] ?? null;
        $whatsapp = $options['whatsapp'] ?? null;
        $country = $options['country'] ?? null;
        $city = $options['city'] ?? null;
        $timezone = $options['timezone'] ?? 'UTC';
        $createStoreAdmin = (bool) ($options['create_store_admin'] ?? false);
        $confirmed = (bool) ($options['confirm_instance_setup'] ?? false);

        return [
            'instance_id' => $instanceId,
            'plan_code' => $planCode,
            'store_name' => $storeName,
            'domain' => $domain,
            'country' => $country,
            'city' => $city,
            'dry_run' => (bool) ($options['dry_run'] ?? true),
            'apply_requested' => (bool) ($options['apply'] ?? false),
            'confirmed' => $confirmed,
            'env' => $this->buildEnvPreview($instanceId, $planCode, $domain),
            'business_settings' => $this->buildBusinessSettingsPreview([
                'store_name' => $storeName,
                'site_motto' => $options['site_motto'] ?? null,
                'meta_title' => $options['meta_title'] ?? $storeName,
                'meta_description' => $options['meta_description'] ?? null,
                'contact_phone' => $options['contact_phone'] ?? $whatsapp,
                'contact_email' => $options['contact_email'] ?? $adminEmail,
                'whatsapp' => $whatsapp,
                'footer_text' => $options['footer_text'] ?? null,
                'timezone' => $timezone,
            ]),
            'store_admin' => [
                'create_requested' => $createStoreAdmin,
                'create_user_later' => ! $createStoreAdmin,
                'user_type' => 'staff',
                'role' => config('coremarket.access.store_admin_role', 'store_admin'),
                'name' => $adminName,
                'email' => $adminEmail,
                'status' => $createStoreAdmin
                    ? 'Preview only in this step. Actual user creation remains blocked until a manual password handoff workflow is approved.'
                    : 'Not requested. Store Admin can be created later during actual setup.',
            ],
            'media' => [
                'required' => [
                    'site_icon',
                    'header_logo',
                    'footer_logo',
                    'system_logo_white',
                    'system_logo_black',
                ],
                'notes' => config('coremarket.instance_setup.media_notes', []),
            ],
            'products' => [
                'import_supported_later' => true,
                'template_columns' => [
                    'name',
                    'description',
                    'category_name',
                    'brand_name',
                    'unit_price',
                    'current_stock',
                    'unit',
                    'sku',
                    'image_filename',
                    'tags',
                    'published',
                ],
            ],
            'checklist' => [
                'Prepare instance-specific .env values outside Git.',
                'Prepare a dedicated database for the managed instance.',
                'Upload client media outside Git and assign upload IDs later.',
                'Create or verify the Store Admin account only after client approval and manual password handoff.',
                'Import or enter products only after branding and settings are confirmed.',
            ],
        ];
    }

    public function validateApplyRequirements(array $plan): array
    {
        $errors = [];

        if (! $plan['apply_requested']) {
            return $errors;
        }

        if (! $plan['confirmed']) {
            $errors[] = 'Apply mode requires --confirm-instance-setup.';
        }

        if (blank($plan['instance_id'])) {
            $errors[] = 'Instance ID is required.';
        }

        if (blank($plan['store_name'])) {
            $errors[] = 'Store name is required for apply mode.';
        }

        if (blank($plan['domain'])) {
            $errors[] = 'Domain is required for apply mode.';
        }

        if (blank($plan['store_admin']['email'])) {
            $errors[] = 'Admin email is required for apply mode.';
        }

        if ($plan['store_admin']['create_requested']) {
            if (blank($plan['store_admin']['name'])) {
                $errors[] = 'Admin name is required when --create-store-admin is used.';
            }

            $errors[] = 'Store Admin creation is preview-only in this step and is not applied yet.';
        }

        return $errors;
    }

    public function applyBusinessSettings(array $settings): array
    {
        $applied = [];

        foreach ($settings as $key => $value) {
            $setting = BusinessSetting::query()->firstOrNew([
                'type' => $key,
                'lang' => null,
            ]);

            $setting->value = $value;
            $wasExisting = $setting->exists;
            $previousValue = $wasExisting ? $setting->getOriginal('value') : null;
            $setting->save();

            $applied[] = [
                'key' => $key,
                'previous' => $previousValue,
                'value' => $value,
                'status' => $wasExisting ? 'updated' : 'created',
            ];
        }

        Cache::forget('business_settings');

        return $applied;
    }

    protected function buildEnvPreview(string $instanceId, string $planCode, ?string $domain): array
    {
        return [
            'APP_NAME' => null,
            'APP_URL' => $domain ? 'https://' . $domain : null,
            'DB_DATABASE' => null,
            'DB_USERNAME' => null,
            'DB_PASSWORD' => '[hidden]',
            'COREMARKET_LICENSE_ENABLED' => 'true',
            'COREMARKET_INSTANCE_ID' => $instanceId,
            'COREMARKET_LICENSE_KEY' => '[set-outside-git]',
            'COREMARKET_LICENSE_DOMAIN' => $domain,
            'COREMARKET_PLAN_CODE' => $planCode,
            'COREMARKET_LICENSE_STATUS' => 'active',
            'COREMARKET_LICENSE_STARTS_AT' => null,
            'COREMARKET_LICENSE_EXPIRES_AT' => null,
            'COREMARKET_LICENSE_GRACE_UNTIL' => null,
        ];
    }

    protected function buildBusinessSettingsPreview(array $input): array
    {
        $settings = [];

        foreach (config('coremarket.instance_setup.business_settings_map', []) as $key => $source) {
            $settings[$key] = is_string($source) ? ($input[$source] ?? null) : $source;
        }

        return $settings;
    }
}
