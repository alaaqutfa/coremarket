<?php

namespace App\Http\Controllers;

use App\Services\CoreMarketFeatureAccessService;
use App\Services\CoreMarketRuntimeSnapshotService;
use App\Services\CorePilotAddonRequestClient;
use Illuminate\Http\Request;

class AddonController extends Controller
{
    public function index(CoreMarketRuntimeSnapshotService $snapshot): \Illuminate\View\View
    {
        $catalog = $snapshot->persistedAddonCatalog();
        $items = is_array($catalog['items'] ?? null) ? $catalog['items'] : $this->fallbackCatalog();
        return view('backend.addons.index', ['addonCatalog' => $items, 'usingFallbackCatalog' => ! is_array($catalog['items'] ?? null), 'requestConfigured' => filled(config('coremarket.corepilot_addon_requests.url')) && filled(config('coremarket.corepilot_addon_requests.token'))]);
    }

    public function store(Request $request, CoreMarketRuntimeSnapshotService $snapshot, CorePilotAddonRequestClient $client)
    {
        $data = $request->validate(['addon_code' => ['required', 'string', 'max:120'], 'setup_requested' => ['nullable', 'boolean'], 'note' => ['nullable', 'string', 'max:2000']]);
        $catalog = $snapshot->persistedAddonCatalog();
        $item = collect($catalog['items'] ?? $this->fallbackCatalog())->firstWhere('code', $data['addon_code']);
        abort_unless($item && ! in_array($item['status'] ?? null, ['included', 'active'], true), 422);
        $store = $snapshot->persistedStoreMetadata();
        try {
            $client->submit(['instance_id' => $store['instance_id'] ?? config('coremarket.license.instance_id'), 'addon_code' => $data['addon_code'], 'setup_requested' => $request->boolean('setup_requested'), 'note' => $data['note'] ?? null, 'catalog_version' => $catalog['catalog_version'] ?? null, 'requested_by' => ['user_id' => auth()->id(), 'name' => auth()->user()?->name, 'email' => auth()->user()?->email]]);
        } catch (\Throwable $exception) { return back()->withErrors(['addon_request' => $exception->getMessage()]); }
        return back()->with('success', 'Feature activation request sent to CorePilotOS for review.');
    }

    public function create() { abort(404); } public function show($id) { abort(404); } public function edit($id) { abort(404); } public function update(Request $request, $id) { abort(404); } public function destroy($id) { abort(404); } public function activation(Request $request) { abort(404); }

    private function fallbackCatalog(): array
    {
        return [[ 'code' => 'blog_content_pages', 'name' => 'Blog / Content Pages', 'description' => 'Demo fallback catalog. Sync CorePilotOS to receive commercial availability.', 'category' => 'small', 'billing_type' => 'monthly', 'monthly_price' => 10, 'setup_available' => false, 'status' => 'available' ]];
    }
}
