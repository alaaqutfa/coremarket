<?php

namespace App\Services;

use App\Models\BusinessSetting;
use App\Models\Currency;
use App\Models\Language;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role as SpatieRole;

class CoreMarketInstanceSetupService
{
    protected ?CoreMarketFeatureAccessService $featureAccess = null;

    public function buildPlan(string $instanceId, array $options = []): array
    {
        $requestedPlanCode = $options['plan'] ?? config('coremarket.instance_setup.default_plan', 'starter');
        $planCode = $this->featureAccess()->resolveAppliedPlan($requestedPlanCode);
        $requestedStoreMode = $options['store_mode'] ?? config('coremarket.instance_setup.default_store_mode', 'single_store');
        $storeMode = $this->featureAccess()->resolveStoreMode($planCode, $requestedStoreMode);
        $storeName = $options['store_name'] ?? null;
        $domain = $options['domain'] ?? null;
        $adminName = $options['admin_name'] ?? null;
        $adminEmail = $options['admin_email'] ?? null;
        $supportEmail = $options['support_email'] ?? $options['contact_email'] ?? $adminEmail;
        $whatsapp = $options['whatsapp'] ?? null;
        $contactPhone = $options['contact_phone'] ?? $whatsapp;
        $contactAddress = $options['contact_address'] ?? null;
        $country = $options['country'] ?? null;
        $city = $options['city'] ?? null;
        $currencyCode = strtoupper((string) ($options['currency'] ?? config('coremarket.instance_setup.default_currency_code', 'USD')));
        $languageCode = strtolower((string) ($options['language'] ?? config('coremarket.instance_setup.default_language_code', 'en')));
        $timezone = $options['timezone'] ?? 'UTC';
        $createStoreAdmin = (bool) ($options['create_store_admin'] ?? false);
        $confirmed = (bool) ($options['confirm_instance_setup'] ?? false);
        $runtimeAccess = $this->featureAccess()->matrixFor($planCode, $storeMode);
        $currency = Schema::hasTable('currencies')
            ? Currency::query()->where('code', $currencyCode)->first()
            : null;
        $language = Schema::hasTable('languages')
            ? Language::query()->where('code', $languageCode)->first()
            : null;
        $existingStoreAdmin = $adminEmail && Schema::hasTable('users')
            ? User::query()->where('email', $adminEmail)->first()
            : null;

        return [
            'instance_id' => $instanceId,
            'plan_code' => $planCode,
            'requested_plan_code' => $requestedPlanCode,
            'store_mode' => $storeMode,
            'requested_store_mode' => $requestedStoreMode,
            'store_name' => $storeName,
            'domain' => $domain,
            'country' => $country,
            'city' => $city,
            'currency' => [
                'code' => $currencyCode,
                'id' => $currency?->id,
                'exists' => $currency !== null,
            ],
            'language' => [
                'code' => $languageCode,
                'id' => $language?->id,
                'exists' => $language !== null,
            ],
            'dry_run' => (bool) ($options['dry_run'] ?? true),
            'apply_requested' => (bool) ($options['apply'] ?? false),
            'confirmed' => $confirmed,
            'runtime_access' => $runtimeAccess,
            'env' => $this->buildEnvPreview($instanceId, $planCode, $storeMode, $domain, $languageCode),
            'business_settings' => $this->buildBusinessSettingsPreview([
                'store_name' => $storeName,
                'site_motto' => $options['site_motto'] ?? null,
                'meta_title' => $options['meta_title'] ?? $storeName,
                'meta_description' => $options['meta_description'] ?? null,
                'contact_address' => $contactAddress,
                'contact_phone' => $contactPhone,
                'contact_email' => $options['contact_email'] ?? $supportEmail,
                'support_email' => $supportEmail,
                'whatsapp' => $whatsapp,
                'footer_text' => $options['footer_text'] ?? null,
                'timezone' => $timezone,
                'system_default_currency' => $currency?->id,
                'vendor_system_activation' => $runtimeAccess['features']['multi_vendor'] ? 1 : 0,
                'wallet_system' => $this->resolveWalletSettingValue($runtimeAccess['features']),
            ]),
            'shops' => $this->buildShopPreview([
                'store_name' => $storeName,
                'shop_slug' => $this->resolveShopSlug($domain, $storeName),
                'contact_address' => $contactAddress,
                'contact_phone' => $contactPhone,
                'meta_title' => $options['meta_title'] ?? $storeName,
                'meta_description' => $options['meta_description'] ?? null,
                'facebook' => null,
                'instagram' => null,
                'google' => null,
                'twitter' => null,
                'youtube' => null,
            ]),
            'store_admin' => [
                'create_requested' => $createStoreAdmin,
                'create_user_later' => ! $createStoreAdmin,
                'user_type' => 'staff',
                'role' => config('coremarket.access.store_admin_role', 'store_admin'),
                'name' => $adminName,
                'email' => $adminEmail,
                'phone' => $contactPhone,
                'password_supplied' => ! empty($options['store_admin_password']),
                'existing_user_type' => $existingStoreAdmin?->user_type,
                'action' => $existingStoreAdmin ? 'update' : 'create',
                'status' => $createStoreAdmin
                    ? 'Will create or update the Store Admin safely during apply mode if role and password requirements pass.'
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
                'Create or verify the Store Admin account only after client approval and secure password handoff.',
                'Import or enter products only after branding and settings are confirmed.',
            ],
            'notes' => [
                'This command does not modify .env files.',
                'Default language remains an environment/runtime concern; this command verifies the requested language exists and previews DEFAULT_LANGUAGE.',
                'Marketplace store mode only enables seller surfaces when the applied plan also allows them.',
            ],
            'options' => [
                'store_admin_password' => $options['store_admin_password'] ?? null,
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

        if (! in_array($plan['plan_code'], ['starter', 'business', 'marketplace', 'enterprise'], true)) {
            $errors[] = 'Unsupported applied plan code.';
        }

        if (! in_array($plan['store_mode'], ['single_store', 'marketplace', 'owned_coremarket_store'], true)) {
            $errors[] = 'Unsupported store mode.';
        }

        if (! $plan['currency']['exists']) {
            $errors[] = 'Requested currency code was not found in the currencies table.';
        }

        if (! $plan['language']['exists']) {
            $errors[] = 'Requested language code was not found in the languages table.';
        }

        if ($plan['store_admin']['create_requested']) {
            if (blank($plan['store_admin']['name'])) {
                $errors[] = 'Admin name is required when --create-store-admin is used.';
            }
            if (! $this->storeAdminRole()) {
                $errors[] = 'The store_admin role is missing in the current database.';
            }

            if ($plan['store_admin']['action'] === 'create' && blank($plan['options']['store_admin_password'])) {
                $errors[] = 'A store admin password is required when creating a new Store Admin.';
            }

            if (
                $plan['store_admin']['action'] === 'update' &&
                $plan['store_admin']['existing_user_type'] !== null &&
                ! in_array($plan['store_admin']['existing_user_type'], ['staff'], true)
            ) {
                $errors[] = 'Existing user with the admin email is not a staff user and will not be converted automatically.';
            }
        }

        return $errors;
    }

    public function applyPlan(array $plan): array
    {
        $result = [
            'business_settings' => $this->applyBusinessSettings($plan['business_settings']),
            'shops' => $this->applyShopSettings($plan['shops']),
            'store_admin' => null,
        ];

        if ($plan['store_admin']['create_requested']) {
            $storeAdmin = $this->createOrUpdateStoreAdmin($plan);
            $result['store_admin'] = [
                'email' => $storeAdmin->email,
                'status' => 'saved',
            ];
        }

        return $result;
    }

    public function applyBusinessSettings(array $settings): array
    {
        $applied = [];

        foreach ($settings as $key => $value) {
            $setting = BusinessSetting::query()
                ->where('type', $key)
                ->whereNull('lang')
                ->first() ?: new BusinessSetting();

            $setting->type = $key;
            $setting->lang = null;
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

    protected function buildEnvPreview(string $instanceId, string $planCode, string $storeMode, ?string $domain, string $languageCode): array
    {
        return [
            'APP_NAME' => null,
            'APP_URL' => $domain ? 'https://' . $domain : null,
            'DEFAULT_LANGUAGE' => $languageCode,
            'DB_DATABASE' => null,
            'DB_USERNAME' => null,
            'DB_PASSWORD' => '[hidden]',
            'COREMARKET_LICENSE_ENABLED' => 'true',
            'COREMARKET_INSTANCE_ID' => $instanceId,
            'COREMARKET_LICENSE_KEY' => '[set-outside-git]',
            'COREMARKET_LICENSE_DOMAIN' => $domain,
            'COREMARKET_APPLIED_PLAN_CODE' => $planCode,
            'COREMARKET_PLAN_CODE' => $planCode,
            'COREMARKET_STORE_MODE' => $storeMode,
            'COREMARKET_LICENSE_STORE_MODE' => $storeMode,
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

        foreach (config('coremarket.instance_setup.safe_setting_defaults', []) as $key => $value) {
            $settings[$key] = $value;
        }

        return $settings;
    }

    protected function buildShopPreview(array $input): array
    {
        if (! Schema::hasTable('shops')) {
            return [];
        }

        $preview = [];

        foreach (DB::table('shops')->get() as $shop) {
            foreach (config('coremarket.instance_setup.shop_field_map', []) as $field => $source) {
                if (! property_exists($shop, $field)) {
                    continue;
                }

                $preview[] = [
                    'id' => $shop->id,
                    'field' => $field,
                    'current_value' => $shop->{$field},
                    'target_value' => $input[$source] ?? null,
                ];
            }
        }

        return $preview;
    }

    protected function resolveWalletSettingValue(array $features): int
    {
        return ! empty($features['wallet_enabled']) ? 1 : 0;
    }

    protected function resolveShopSlug(?string $domain, ?string $storeName): ?string
    {
        $source = $domain ?: $storeName;

        if (blank($source)) {
            return null;
        }

        return Str::slug((string) $source);
    }

    protected function createOrUpdateStoreAdmin(array $plan): User
    {
        $role = $this->storeAdminRole();
        $storeAdmin = User::query()->where('email', $plan['store_admin']['email'])->first() ?: new User();

        $storeAdmin->email = $plan['store_admin']['email'];
        $storeAdmin->user_type = 'staff';
        $storeAdmin->name = $plan['store_admin']['name'];
        $storeAdmin->phone = $plan['store_admin']['phone'];
        $storeAdmin->email_verified_at = now();
        $storeAdmin->banned = 0;

        if (! empty($plan['options']['store_admin_password'])) {
            $storeAdmin->password = Hash::make($plan['options']['store_admin_password']);
        }

        $storeAdmin->save();
        $storeAdmin->syncRoles([$role]);

        $staff = Staff::query()->where('user_id', $storeAdmin->id)->first() ?: new Staff();
        $staff->user_id = $storeAdmin->id;
        $staff->role_id = $role->id;
        $staff->save();

        return $storeAdmin;
    }

    protected function applyShopSettings(array $shops): array
    {
        $applied = [];

        foreach ($shops as $shopRow) {
            $shop = DB::table('shops')->where('id', $shopRow['id'])->first();

            if (! $shop) {
                continue;
            }

            $previousValue = $shop->{$shopRow['field']};
            DB::table('shops')
                ->where('id', $shopRow['id'])
                ->update([
                    $shopRow['field'] => $shopRow['target_value'],
                    'updated_at' => now(),
                ]);

            $applied[] = [
                'id' => $shopRow['id'],
                'field' => $shopRow['field'],
                'previous' => $previousValue,
                'value' => $shopRow['target_value'],
                'status' => 'updated',
            ];
        }

        return $applied;
    }

    protected function storeAdminRole(): ?SpatieRole
    {
        if (! Schema::hasTable('roles')) {
            return null;
        }

        return SpatieRole::query()
            ->where('name', config('coremarket.access.store_admin_role', 'store_admin'))
            ->where('guard_name', 'web')
            ->first();
    }

    protected function featureAccess(): CoreMarketFeatureAccessService
    {
        return $this->featureAccess ??= app(CoreMarketFeatureAccessService::class);
    }
}
