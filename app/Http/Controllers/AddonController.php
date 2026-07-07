<?php

namespace App\Http\Controllers;

use App\Services\CoreMarketFeatureAccessService;
use Illuminate\Http\Request;

class AddonController extends Controller
{
    public function index(CoreMarketFeatureAccessService $featureAccess)
    {
        $catalog = collect([
            [
                'key' => 'multi_vendor_request',
                'title' => 'Multi Vendor / Sellers',
                'description' => 'Enable marketplace seller onboarding, seller management, and vendor-facing catalog flows.',
                'required_features' => ['multi_vendor', 'sellers'],
                'available_plans' => ['marketplace', 'enterprise'],
            ],
            [
                'key' => 'pos_request',
                'title' => 'POS',
                'description' => 'Enable point-of-sale surfaces and related in-store selling workflows when supported.',
                'required_features' => ['pos'],
                'available_plans' => ['business', 'marketplace', 'enterprise'],
            ],
            [
                'key' => 'blog_request',
                'title' => 'Blog System',
                'description' => 'Enable blog publishing surfaces for store announcements, content marketing, and updates.',
                'required_features' => ['blog'],
                'available_plans' => ['starter', 'business', 'marketplace', 'enterprise'],
            ],
            [
                'key' => 'marketing_pro_request',
                'title' => 'Marketing Pro',
                'description' => 'Advanced marketing tools such as richer notifications, campaigns, and customer targeting.',
                'required_features' => ['marketing_advanced'],
                'available_plans' => ['business', 'marketplace', 'enterprise'],
            ],
            [
                'key' => 'support_pro_request',
                'title' => 'Support Pro',
                'description' => 'Advanced support tooling including richer customer assistance and product query workflows.',
                'required_features' => ['support_advanced'],
                'available_plans' => ['business', 'marketplace', 'enterprise'],
            ],
            [
                'key' => 'loyalty_points_request',
                'title' => 'Loyalty Points',
                'description' => 'Enable points-based customer loyalty surfaces supported by the current runtime feature set.',
                'required_features' => ['loyalty_points'],
                'available_plans' => ['starter', 'business', 'marketplace', 'enterprise'],
            ],
            [
                'key' => 'advanced_reports_request',
                'title' => 'Advanced Reports',
                'description' => 'Unlock advanced reporting and deeper operational insights beyond the basic reporting set.',
                'required_features' => ['reports_advanced'],
                'available_plans' => ['business', 'marketplace', 'enterprise'],
            ],
            [
                'key' => 'staff_management_request',
                'title' => 'Staff Management',
                'description' => 'Expand staff access and management controls for larger internal operations teams.',
                'required_features' => ['staff_management'],
                'available_plans' => ['starter', 'business', 'marketplace', 'enterprise'],
            ],
            [
                'key' => 'custom_domain_request',
                'title' => 'Custom Domain',
                'description' => 'Use your own branded domain with managed DNS, SSL, and instance support configuration.',
                'required_features' => [],
                'available_plans' => ['starter', 'business', 'marketplace', 'enterprise'],
            ],
            [
                'key' => 'payment_gateway_request',
                'title' => 'Payment Gateway Setup',
                'description' => 'Request online payment gateway enablement and managed configuration review.',
                'required_features' => [],
                'available_plans' => ['business', 'marketplace', 'enterprise'],
            ],
        ])->map(function (array $addon) use ($featureAccess) {
            $requiredFeatures = $addon['required_features'];
            $isEnabled = ! empty($requiredFeatures)
                && collect($requiredFeatures)->every(fn (string $feature) => $featureAccess->enabled($feature));

            $appliedPlan = $featureAccess->appliedPlan();
            $availableInPlan = in_array($appliedPlan, $addon['available_plans'], true);

            return array_merge($addon, [
                'enabled' => $isEnabled,
                'available_in_plan' => $availableInPlan,
                'status_label' => $isEnabled ? 'Enabled' : 'Disabled',
                'plan_label' => $availableInPlan ? 'Available in your plan' : 'Requires upgrade',
            ]);
        })->values();

        return view('backend.addons.index', [
            'addonCatalog' => $catalog,
            'appliedPlan' => $featureAccess->appliedPlan(),
            'storeMode' => $featureAccess->storeMode(),
            'requestUrl' => $this->supportRequestUrl(),
            'requestChannel' => $this->supportRequestChannel(),
            'supportEmail' => get_setting('contact_email') ?: config('mail.from.address'),
            'isStoreAdminViewer' => isStoreAdmin(),
        ]);
    }

    public function create()
    {
        abort(404);
    }

    public function store(Request $request)
    {
        abort(404);
    }

    public function show($id)
    {
        abort(404);
    }

    public function edit($id)
    {
        abort(404);
    }

    public function update(Request $request, $id)
    {
        abort(404);
    }

    public function destroy($id)
    {
        abort(404);
    }

    public function activation(Request $request)
    {
        abort(404);
    }

    private function supportRequestUrl(): ?string
    {
        $whatsAppUrl = coremarketWhatsAppUrl(
            'Hello, I would like to request add-on activation for ' . coremarketStoreName() . '.'
        );

        if ($whatsAppUrl !== null) {
            return $whatsAppUrl;
        }

        $supportEmail = get_setting('contact_email') ?: config('mail.from.address');

        if (filled($supportEmail)) {
            return 'mailto:' . $supportEmail . '?subject=' . rawurlencode('CoreMarket add-on request');
        }

        return null;
    }

    private function supportRequestChannel(): string
    {
        if (coremarketWhatsAppUrl('CoreMarket add-on request') !== null) {
            return 'WhatsApp';
        }

        return filled(get_setting('contact_email') ?: config('mail.from.address'))
            ? 'Email'
            : 'Support';
    }
}
