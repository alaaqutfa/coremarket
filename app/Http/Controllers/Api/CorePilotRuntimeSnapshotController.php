<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CoreMarketRuntimeSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CorePilotRuntimeSnapshotController extends Controller
{
    public function preview(Request $request, CoreMarketRuntimeSnapshotService $runtimeSnapshotService): JsonResponse
    {
        $payload = $this->validatedPayload($request);

        return response()->json([
            'result' => true,
            'mode' => 'preview',
            'runtime' => $runtimeSnapshotService->preview($payload),
        ]);
    }

    public function apply(Request $request, CoreMarketRuntimeSnapshotService $runtimeSnapshotService): JsonResponse
    {
        $payload = $this->validatedPayload($request);

        return response()->json([
            'result' => true,
            'mode' => 'apply',
            'runtime' => $runtimeSnapshotService->apply($payload),
        ]);
    }

    protected function validatedPayload(Request $request): array
    {
        $featureAccess = app(\App\Services\CoreMarketFeatureAccessService::class);

        $featureRules = collect($featureAccess->featureKeys())
            ->mapWithKeys(fn (string $key) => ["features.{$key}" => ['nullable', 'boolean']])
            ->all();

        $limitRules = collect($featureAccess->limitKeys())
            ->mapWithKeys(fn (string $key) => ["limits.{$key}" => ['nullable', 'integer', 'min:0']])
            ->all();

        return $request->validate(array_merge([
            'status' => ['required', Rule::in(['active', 'inactive', 'suspended', 'expired'])],
            'applied_plan' => ['required', 'string'],
            'store_mode' => ['nullable', 'string'],
            'features' => ['nullable', 'array'],
            'limits' => ['nullable', 'array'],
            'store' => ['nullable', 'array'],
            'store.instance_id' => ['nullable', 'string', 'max:190'],
            'store.store_name' => ['nullable', 'string', 'max:255'],
            'store.store_url' => ['nullable', 'url', 'max:2048'],
            'store.admin_url' => ['nullable', 'url', 'max:2048'],
            'store.pos_url' => ['nullable', 'url', 'max:2048'],
            'store.api_base_url' => ['nullable', 'url', 'max:2048'],
            'support' => ['nullable', 'array'],
            'support.company_name' => ['nullable', 'string', 'max:255'],
            'support.support_email' => ['nullable', 'email', 'max:255'],
        ], $featureRules, $limitRules));
    }
}
