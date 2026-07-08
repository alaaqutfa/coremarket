<?php

namespace App\Http\Controllers;

use App\Services\CoreMarketFeatureAccessService;
use App\Services\CoreMarketLicenseService;
use Illuminate\Http\Request;
use App\Models\BusinessSetting;
use App\Models\PaymentMethod;
use App\Models\Upload;
use Artisan;
use CoreComponentRepository;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\URL;
use Str;

class BusinessSettingsController extends Controller
{
    public function __construct()
    {
        // Staff Permission Check
        $this->middleware(['permission:seller_commission_configuration'])->only('vendor_commission');
        $this->middleware(['permission:seller_verification_form_configuration'])->only('seller_verification_form');
        $this->middleware(['permission:general_settings'])->only('general_setting');
        $this->middleware(['permission:features_activation'])->only('activation');
        $this->middleware(['permission:smtp_settings'])->only('smtp_settings');
        $this->middleware(['permission:payment_methods_configurations'])->only('payment_method');
        $this->middleware(['permission:order_configuration'])->only('order_configuration');
        $this->middleware(['permission:file_system_&_cache_configuration'])->only('file_system');
        $this->middleware(['permission:social_media_logins'])->only('social_login');
        $this->middleware(['permission:facebook_chat'])->only('facebook_chat');
        $this->middleware(['permission:facebook_comment'])->only('facebook_comment');
        $this->middleware(['permission:analytics_tools_configuration'])->only('google_analytics');
        $this->middleware(['permission:google_recaptcha_configuration'])->only('google_recaptcha');
        $this->middleware(['permission:google_map_setting'])->only('google_map');
        $this->middleware(['permission:google_firebase_setting'])->only('google_firebase');
        $this->middleware(['permission:shipping_configuration'])->only('shipping_configuration');
    }

    public function general_setting(Request $request)
    {
        // CoreComponentRepository::instantiateShopRepository();
        // // CoreComponentRepository::initializeCache();
        return view('backend.setup_configurations.general_settings');
    }

    public function activation(
        Request $request,
        CoreMarketLicenseService $licenseService,
        CoreMarketFeatureAccessService $featureAccess
    )
    {
        abort_if(isStoreAdmin(), 403);

        return view('backend.setup_configurations.activation', $this->buildRuntimeOverviewData(
            $licenseService,
            $featureAccess
        ));
    }

    public function subscriptionOverview(
        Request $request,
        CoreMarketLicenseService $licenseService,
        CoreMarketFeatureAccessService $featureAccess
    )
    {
        return view('backend.setup_configurations.subscription_overview', $this->buildRuntimeOverviewData(
            $licenseService,
            $featureAccess
        ));
    }

    public function social_login(Request $request)
    {
        // CoreComponentRepository::instantiateShopRepository();
        // CoreComponentRepository::initializeCache();
        return view('backend.setup_configurations.social_login');
    }

    public function smtp_settings(Request $request)
    {
        // CoreComponentRepository::instantiateShopRepository();
        // CoreComponentRepository::initializeCache();
        return view('backend.setup_configurations.smtp_settings');
    }

    public function google_analytics(Request $request)
    {
        // CoreComponentRepository::instantiateShopRepository();
        // CoreComponentRepository::initializeCache();
        return view('backend.setup_configurations.google_configuration.google_analytics');
    }

    public function google_recaptcha(Request $request)
    {
        // CoreComponentRepository::instantiateShopRepository();
        // CoreComponentRepository::initializeCache();
        return view('backend.setup_configurations.google_configuration.google_recaptcha');
    }

    public function google_map(Request $request)
    {
        // CoreComponentRepository::instantiateShopRepository();
        // CoreComponentRepository::initializeCache();
        return view('backend.setup_configurations.google_configuration.google_map');
    }

    public function google_firebase(Request $request)
    {
        // CoreComponentRepository::instantiateShopRepository();
        // CoreComponentRepository::initializeCache();
        return view('backend.setup_configurations.google_configuration.google_firebase');
    }

    public function facebook_chat(Request $request)
    {
        // CoreComponentRepository::instantiateShopRepository();
        // CoreComponentRepository::initializeCache();
        return view('backend.setup_configurations.facebook_chat');
    }

    public function facebook_comment(Request $request)
    {
        // CoreComponentRepository::instantiateShopRepository();
        // CoreComponentRepository::initializeCache();
        return view('backend.setup_configurations.facebook_configuration.facebook_comment');
    }

    public function payment_method(Request $request)
    {
        // CoreComponentRepository::instantiateShopRepository();
        // CoreComponentRepository::initializeCache();
        $payment_methods = PaymentMethod::whereNull('addon_identifier')->get();
        return view('backend.setup_configurations.payment_method.index', compact('payment_methods'));
    }

    public function file_system(Request $request)
    {
        // CoreComponentRepository::instantiateShopRepository();
        return view('backend.setup_configurations.file_system');
    }

    private function buildRuntimeOverviewData(
        CoreMarketLicenseService $licenseService,
        CoreMarketFeatureAccessService $featureAccess
    ): array
    {
        $licenseSnapshot = $licenseService->snapshot();
        $featureMatrix = $featureAccess->matrixFor();
        $currentProductCount = $licenseService->currentProductCount();
        $currentMonthlyOrderCount = $licenseService->currentMonthlyOrderCount();
        $currentUploadCount = Upload::query()->count();

        $storeInfo = [
            'store_name' => coremarketStoreName(),
            'domain' => $licenseSnapshot['domain'] ?: (parse_url(config('app.url', ''), PHP_URL_HOST) ?: config('app.url')),
            'app_url' => config('app.url'),
            'instance_id' => $licenseSnapshot['instance_id'],
            'support_email' => get_setting('contact_email') ?: config('mail.from.address'),
            'contact_phone' => get_setting('contact_phone') ?: get_setting('helpline_number'),
            'support_owner_email' => config('mail.from.address'),
        ];

        $featureRows = collect($featureMatrix['features'])
            ->map(function ($enabled, string $key) {
                return [
                    'key' => $key,
                    'label' => Str::of($key)->replace('_', ' ')->title()->toString(),
                    'enabled' => (bool) $enabled,
                ];
            })
            ->sortBy('label')
            ->values();

        $enabledFeatureRows = $featureRows
            ->where('enabled', true)
            ->values();

        $disabledFeatureRows = $featureRows
            ->where('enabled', false)
            ->values();

        $limitRows = collect($featureMatrix['limits'])
            ->map(function ($value, string $key) use ($currentProductCount, $currentMonthlyOrderCount, $currentUploadCount) {
                $usage = null;
                $usageNote = null;
                $label = match ($key) {
                    'storage_mb_limit' => 'Media storage limit (MB)',
                    default => Str::of($key)->replace('_', ' ')->title()->toString(),
                };

                if ($key === 'products_limit') {
                    $usage = $currentProductCount;
                } elseif ($key === 'monthly_orders_limit') {
                    $usage = $currentMonthlyOrderCount;
                } elseif ($key === 'storage_mb_limit') {
                    $usage = $currentUploadCount;
                    $usageNote = 'Uploads count shown as a safe placeholder. Media file size is not tracked reliably here yet.';
                }

                return [
                    'key' => $key,
                    'label' => $label,
                    'value' => $value,
                    'usage' => $usage,
                    'usage_note' => $usageNote,
                ];
            })
            ->values();

        $setupChecklist = [
            [
                'label' => 'License runtime',
                'state' => $licenseService->isActive() ? 'ok' : 'attention',
                'summary' => $licenseService->isActive()
                    ? 'Runtime access is active for store management and orders.'
                    : 'Runtime access needs owner attention before normal store operations continue.',
            ],
            [
                'label' => 'Store identity',
                'state' => filled($storeInfo['store_name']) ? 'ok' : 'warning',
                'summary' => filled($storeInfo['store_name'])
                    ? 'A storefront name fallback is available for white-label output.'
                    : 'Storefront name is missing and should be configured before client launch.',
            ],
            [
                'label' => 'Domain and contact',
                'state' => filled($storeInfo['domain']) && (filled($storeInfo['support_email']) || filled($storeInfo['contact_phone'])) ? 'ok' : 'warning',
                'summary' => filled($storeInfo['domain'])
                    ? 'Domain context is present. Support contact can be refined through managed instance setup.'
                    : 'No domain was resolved from the runtime snapshot or app URL.',
            ],
            [
                'label' => 'Single-store safety defaults',
                'state' => ! get_setting('vendor_system_activation') && ! get_setting('wallet_system') && ! get_setting('show_website_popup')
                    ? 'ok'
                    : 'warning',
                'summary' => 'Vendor activation, wallet visibility, and popup marketing should stay disabled unless the applied plan explicitly enables them.',
            ],
            [
                'label' => 'Checkout fallback',
                'state' => get_setting('cash_payment') == 1 || $featureAccess->enabled('payment_gateway_enabled')
                    ? 'ok'
                    : 'warning',
                'summary' => 'Starter instances should retain a safe manual or COD checkout path when online gateways are disabled.',
            ],
        ];

        $quickActions = collect([
            [
                'label' => 'Manage Products',
                'route' => 'products.admin',
                'show' => auth()->user()?->can('show_in_house_products'),
            ],
            [
                'label' => 'Manage Categories',
                'route' => 'categories.index',
                'show' => auth()->user()?->can('view_product_categories'),
            ],
            [
                'label' => 'View Orders',
                'route' => 'inhouse_orders.index',
                'show' => auth()->user()?->can('view_inhouse_orders'),
            ],
            [
                'label' => 'Manage Translations',
                'route' => 'website.translations.index',
                'show' => $featureAccess->enabled('translations_limited') && auth()->user()?->can('header_setup'),
            ],
            [
                'label' => 'Manage Currency Rates',
                'route' => 'website.currency-rates.index',
                'show' => $featureAccess->enabled('currencies_limited') && auth()->user()?->can('footer_setup'),
            ],
            [
                'label' => 'Addon Requests',
                'route' => 'addons.index',
                'show' => $featureAccess->enabled('addon_requests'),
            ],
        ])->filter(fn (array $action) => $action['show'] && app('router')->has($action['route']))
            ->map(fn (array $action) => [
                'label' => $action['label'],
                'url' => route($action['route']),
            ])
            ->values();

        return [
            'licenseSnapshot' => $licenseSnapshot,
            'featureMatrix' => $featureMatrix,
            'featureRows' => $featureRows,
            'enabledFeatureRows' => $enabledFeatureRows,
            'disabledFeatureRows' => $disabledFeatureRows,
            'limitRows' => $limitRows,
            'storeInfo' => $storeInfo,
            'setupChecklist' => $setupChecklist,
            'currentProductCount' => $currentProductCount,
            'currentMonthlyOrderCount' => $currentMonthlyOrderCount,
            'currentUploadCount' => $currentUploadCount,
            'quickActions' => $quickActions,
            'isLicenseActive' => $licenseService->isActive(),
            'isLicenseSuspended' => $licenseService->isSuspended(),
            'isLicenseExpired' => $licenseService->isExpired(),
            'isInGracePeriod' => $licenseService->isInGracePeriod(),
            'subscriptionStatusNote' => $licenseService->isActive()
                ? 'Managed by CorePilotOS. Contact support to upgrade or activate features.'
                : 'Subscription status requires attention. Contact support to restore or upgrade access.',
        ];
    }

    /**
     * Update the API key's for payment methods.
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function payment_method_update(Request $request)
    {
        $this->abortIfAdminEnvWritesDisabled();

        foreach ($request->types as $key => $type) {
            $this->overWriteEnvFile($type, $request[$type]);
        }

        $business_settings = BusinessSetting::where('type', $request->payment_method . '_sandbox')->first();
        if ($business_settings != null) {
            if ($request->has($request->payment_method . '_sandbox')) {
                $business_settings->value = 1;
                $business_settings->save();
            } else {
                $business_settings->value = 0;
                $business_settings->save();
            }
        }

        Artisan::call('cache:clear');

        flash(translate("Settings updated successfully"))->success();
        return back();
    }

    /**
     * Update the API key's for GOOGLE analytics.
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function google_analytics_update(Request $request)
    {
        $this->abortIfAdminEnvWritesDisabled();

        foreach ($request->types as $key => $type) {
            $this->overWriteEnvFile($type, $request[$type]);
        }

        $business_settings = BusinessSetting::where('type', 'google_analytics')->first();

        if ($request->has('google_analytics')) {
            $business_settings->value = 1;
            $business_settings->save();
        } else {
            $business_settings->value = 0;
            $business_settings->save();
        }

        Artisan::call('cache:clear');

        flash(translate("Settings updated successfully"))->success();
        return back();
    }

    public function google_recaptcha_update(Request $request)
    {
        $this->abortIfAdminEnvWritesDisabled();

        foreach ($request->types as $key => $type) {
            $this->overWriteEnvFile($type, $request[$type]);
        }

        $business_settings = BusinessSetting::where('type', 'google_recaptcha')->first();

        if ($request->has('google_recaptcha')) {
            $business_settings->value = 1;
            $business_settings->save();
        } else {
            $business_settings->value = 0;
            $business_settings->save();
        }

        Artisan::call('cache:clear');

        flash(translate("Settings updated successfully"))->success();
        return back();
    }

    public function google_map_update(Request $request)
    {
        $this->abortIfAdminEnvWritesDisabled();

        foreach ($request->types as $key => $type) {
            $this->overWriteEnvFile($type, $request[$type]);
        }

        $business_settings = BusinessSetting::where('type', 'google_map')->first();

        if ($request->has('google_map')) {
            $business_settings->value = 1;
            $business_settings->save();
        } else {
            $business_settings->value = 0;
            $business_settings->save();
        }

        Artisan::call('cache:clear');

        flash(translate("Settings updated successfully"))->success();
        return back();
    }

    public function google_firebase_update(Request $request)
    {
        $this->abortIfAdminEnvWritesDisabled();

        foreach ($request->types as $key => $type) {
            $this->overWriteEnvFile($type, $request[$type]);
        }

        $business_settings = BusinessSetting::where('type', 'google_firebase')->first();

        if ($request->has('google_firebase')) {
            $business_settings->value = 1;
            $business_settings->save();
        } else {
            $business_settings->value = 0;
            $business_settings->save();
        }

        Artisan::call('cache:clear');

        flash(translate("Settings updated successfully"))->success();
        return back();
    }


    /**
     * Update the API key's for GOOGLE analytics.
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function facebook_chat_update(Request $request)
    {
        $this->abortIfAdminEnvWritesDisabled();

        foreach ($request->types as $key => $type) {
            $this->overWriteEnvFile($type, $request[$type]);
        }

        $business_settings = BusinessSetting::where('type', 'facebook_chat')->first();

        if ($request->has('facebook_chat')) {
            $business_settings->value = 1;
            $business_settings->save();
        } else {
            $business_settings->value = 0;
            $business_settings->save();
        }

        Artisan::call('cache:clear');

        flash(translate("Settings updated successfully"))->success();
        return back();
    }

    public function facebook_comment_update(Request $request)
    {
        $this->abortIfAdminEnvWritesDisabled();

        foreach ($request->types as $key => $type) {
            $this->overWriteEnvFile($type, $request[$type]);
        }

        $business_settings = BusinessSetting::where('type', 'facebook_comment')->first();
        if (!$business_settings) {
            $business_settings = new BusinessSetting;
            $business_settings->type = 'facebook_comment';
        }

        $business_settings->value = 0;
        if ($request->facebook_comment) {
            $business_settings->value = 1;
        }

        $business_settings->save();

        Artisan::call('cache:clear');

        flash(translate("Settings updated successfully"))->success();
        return back();
    }

    public function facebook_pixel_update(Request $request)
    {
        $this->abortIfAdminEnvWritesDisabled();

        foreach ($request->types as $key => $type) {
            $this->overWriteEnvFile($type, $request[$type]);
        }

        $business_settings = BusinessSetting::where('type', 'facebook_pixel')->first();

        if ($request->has('facebook_pixel')) {
            $business_settings->value = 1;
            $business_settings->save();
        } else {
            $business_settings->value = 0;
            $business_settings->save();
        }

        Artisan::call('cache:clear');

        flash(translate("Settings updated successfully"))->success();
        return back();
    }

    /**
     * Update the API key's for other methods.
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function env_key_update(Request $request)
    {
        $this->abortIfAdminEnvWritesDisabled();

        foreach ($request->types as $key => $type) {
            $this->overWriteEnvFile($type, $request[$type]);
        }

        flash(translate("Settings updated successfully"))->success();
        return back();
    }

    /**
     * overWrite the Env File values.
     * @param  String type
     * @param  String value
     * @return \Illuminate\Http\Response
     */
    public function overWriteEnvFile($type, $val)
    {
        if (env('DEMO_MODE') != 'On') {
            $path = base_path('.env');
            if (file_exists($path)) {
                $val = '"' . trim($val) . '"';
                if (is_numeric(strpos(file_get_contents($path), $type)) && strpos(file_get_contents($path), $type) >= 0) {
                    file_put_contents($path, str_replace(
                        $type . '="' . env($type) . '"',
                        $type . '=' . $val,
                        file_get_contents($path)
                    ));
                } else {
                    file_put_contents($path, file_get_contents($path) . "\r\n" . $type . '=' . $val);
                }
            }
        }
    }

    public function seller_verification_form(Request $request)
    {
        return view('backend.sellers.seller_verification_form.index');
    }

    /**
     * Update sell verification form.
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function seller_verification_form_update(Request $request)
    {
        $form = array();
        $select_types = ['select', 'multi_select', 'radio'];
        $j = 0;
        for ($i = 0; $i < count($request->type); $i++) {
            $item['type'] = $request->type[$i];
            $item['label'] = $request->label[$i];
            if (in_array($request->type[$i], $select_types)) {
                $item['options'] = json_encode($request['options_' . $request->option[$j]]);
                $j++;
            }
            array_push($form, $item);
        }
        $business_settings = BusinessSetting::where('type', 'verification_form')->first();
        $business_settings->value = json_encode($form);
        if ($business_settings->save()) {
            Artisan::call('cache:clear');

            flash(translate("Verification form updated successfully"))->success();
            return back();
        }
    }

    public function update(Request $request)
    {
        if (isStoreAdmin()) {
            $allowedTypes = config('coremarket.access.store_admin_allowed_business_setting_types', []);
            $requestTypes = collect($request->types ?? [])
                ->map(function ($type) {
                    if (is_array($type)) {
                        return array_values($type)[0] ?? null;
                    }

                    return $type;
                })
                ->filter()
                ->values();

            $hasDisallowedType = $requestTypes->contains(function ($type) use ($allowedTypes) {
                return !in_array($type, $allowedTypes, true);
            });

            abort_if($hasDisallowedType, 403);
        }

        foreach ($request->types as $key => $type) {
            if ($type == 'site_name') {
                $this->overWriteEnvFile('APP_NAME', $request[$type]);
            }
            if ($type == 'timezone') {
                $this->overWriteEnvFile('APP_TIMEZONE', $request[$type]);
            } else {
                $lang = null;
                if (gettype($type) == 'array') {
                    $lang = array_key_first($type);
                    $type = $type[$lang];
                    $business_settings = BusinessSetting::where('type', $type)->where('lang', $lang)->first();
                } else {
                    $business_settings = BusinessSetting::where('type', $type)->first();
                }

                if ($business_settings != null) {
                    if (gettype($request[$type]) == 'array') {
                        $business_settings->value = json_encode($request[$type]);
                    } else {
                        $business_settings->value = $request[$type];
                    }
                    $business_settings->lang = $lang;
                    $business_settings->save();
                } else {
                    $business_settings = new BusinessSetting;
                    $business_settings->type = $type;
                    if (gettype($request[$type]) == 'array') {
                        $business_settings->value = json_encode($request[$type]);
                    } else {
                        $business_settings->value = $request[$type];
                    }
                    $business_settings->lang = $lang;
                    $business_settings->save();
                }
            }
        }

        Artisan::call('cache:clear');

        flash(translate("Settings updated successfully"))->success();
        // If the request from a tabs with tab input
        if ($request->has('tab')) {
            return Redirect::to(URL::previous() . "#" . $request->tab);
        }
        return redirect()->back();
    }

    public function updateActivationSettings(Request $request)
    {
        $env_changes = ['FORCE_HTTPS', 'FILESYSTEM_DRIVER'];
        if (in_array($request->type, $env_changes)) {

            return $this->updateActivationSettingsInEnv($request);
        }

        $business_settings = BusinessSetting::where('type', $request->type)->first();
        if ($business_settings != null) {
            if ($request->type == 'maintenance_mode' && $request->value == '1') {
                if (env('DEMO_MODE') != 'On') {
                    Artisan::call('down');
                }
            } elseif ($request->type == 'maintenance_mode' && $request->value == '0') {
                if (env('DEMO_MODE') != 'On') {
                    Artisan::call('up');
                }
            }
            $business_settings->value = $request->value;
            $business_settings->save();
        } else {
            $business_settings = new BusinessSetting;
            $business_settings->type = $request->type;
            $business_settings->value = $request->value;
            $business_settings->save();
        }

        Artisan::call('cache:clear');
        return 1;
    }

    public function updatePaymentActivationSettings(Request $request)
    {
        $payment_method = PaymentMethod::findOrFail($request->id);
        $payment_method->active = $request->value;
        $payment_method->save();

        Artisan::call('cache:clear');
        return 1;
    }

    public function updateActivationSettingsInEnv($request)
    {
        $this->abortIfAdminEnvWritesDisabled();

        if ($request->type == 'FORCE_HTTPS' && $request->value == '1') {
            $this->overWriteEnvFile($request->type, 'On');

            if (strpos(env('APP_URL'), 'http:') !== FALSE) {
                $this->overWriteEnvFile('APP_URL', str_replace("http:", "https:", env('APP_URL')));
            }
        } elseif ($request->type == 'FORCE_HTTPS' && $request->value == '0') {
            $this->overWriteEnvFile($request->type, 'Off');
            if (strpos(env('APP_URL'), 'https:') !== FALSE) {
                $this->overWriteEnvFile('APP_URL', str_replace("https:", "http:", env('APP_URL')));
            }
        } elseif ($request->type == 'FILESYSTEM_DRIVER') {
            $this->overWriteEnvFile($request->type, $request->value);
        }

        return 1;
    }

    public function vendor_commission(Request $request)
    {
        return view('backend.sellers.seller_commission.index');
    }

    public function vendor_commission_update(Request $request)
    {
        foreach ($request->types as $key => $type) {
            $business_settings = BusinessSetting::where('type', $type)->first();
            if ($business_settings != null) {
                $business_settings->value = $request[$type];
                $business_settings->save();
            } else {
                $business_settings = new BusinessSetting;
                $business_settings->type = $type;
                $business_settings->value = $request[$type];
                $business_settings->save();
            }
        }

        Artisan::call('cache:clear');

        flash(translate('Seller Commission updated successfully'))->success();
        return back();
    }

    public function shipping_configuration(Request $request)
    {
        return view('backend.setup_configurations.shipping_configuration.index');
    }

    public function shipping_configuration_update(Request $request)
    {
        $business_settings = BusinessSetting::where('type', $request->type)->first();
        $business_settings->value = $request[$request->type];
        $business_settings->save();

        Artisan::call('cache:clear');
        flash(translate('Shipping Method updated successfully'))->success();
        return back();
    }

    public function order_configuration()
    {
        return view('backend.setup_configurations.order_configuration.index');
    }

    public function import_data(Request $request)
    {
        abort_unless($this->legacyMaintenanceRoutesEnabled(), 404);

        if (env("DEMO_MODE") == "On"){
            flash(translate('Demo data import will not work in demo site'))->error();
            return back();
        }
        $url = 'https://activeitzone.com/ecommerce-demo-data-import/import';
        $header = array(
            'Content-Type:application/json'
        );
        $data['main_url'] = $request->main_url;
        $data['domain'] = $request->domain;
        $data['purchase_key'] = $request->purchase_key;
        $data['layout'] = $request->layout;
        $request_data_json = json_encode($data);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $request_data_json);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        $raw_file_data = curl_exec($ch);

        if(json_decode($raw_file_data, true)['status']) {
            flash(translate('Demo data uploaded successfully'))->success();
        } else {
            flash(translate(json_decode($raw_file_data, true)['message']))->error();
        }

        return back();
    }

    protected function abortIfAdminEnvWritesDisabled(): void
    {
        abort_unless($this->adminEnvWritesEnabled(), 403);
    }

    protected function adminEnvWritesEnabled(): bool
    {
        return filter_var(env('ALLOW_ADMIN_ENV_WRITES', false), FILTER_VALIDATE_BOOL);
    }

    protected function legacyMaintenanceRoutesEnabled(): bool
    {
        return filter_var(env('ENABLE_LEGACY_MAINTENANCE_ROUTES', false), FILTER_VALIDATE_BOOL);
    }
}
