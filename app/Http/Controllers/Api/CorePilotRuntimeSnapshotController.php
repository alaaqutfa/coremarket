<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CoreMarketRuntimeSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class CorePilotRuntimeSnapshotController extends Controller
{
    public function preview(Request $request, CoreMarketRuntimeSnapshotService $runtimeSnapshotService): JsonResponse
    {
        return $this->handleSnapshotRequest($request, $runtimeSnapshotService, 'preview');
    }

    public function apply(Request $request, CoreMarketRuntimeSnapshotService $runtimeSnapshotService): JsonResponse
    {
        return $this->handleSnapshotRequest($request, $runtimeSnapshotService, 'apply');
    }

    public function diagnostics(Request $request, CoreMarketRuntimeSnapshotService $runtimeSnapshotService): JsonResponse
    {
        abort_unless(app()->environment('local') && config('app.debug'), 404);

        $diagnostics = $runtimeSnapshotService->storageDiagnostics();

        return response()->json([
            'app_environment' => app()->environment(),
            'base_path' => base_path(),
            'public_path' => public_path(),
            'environment_file_path' => app()->environmentFilePath(),
            'environment_file_exists' => is_file(app()->environmentFilePath()),
            'config_cached' => app()->configurationIsCached(),
            'default_connection_name' => $diagnostics['default_connection_name'] ?? null,
            'default_database_name' => $diagnostics['default_database_name'] ?? null,
            'runtime_connection_name' => $diagnostics['runtime_connection_name'] ?? null,
            'runtime_database_name' => $diagnostics['runtime_database_name'] ?? null,
            'business_settings_exists' => $diagnostics['has_business_settings_table'] ?? false,
            'forbidden_database_detected' => $diagnostics['forbidden_database_detected'] ?? true,
            'env_db_database' => env('DB_DATABASE'),
            'getenv_db_database' => getenv('DB_DATABASE') ?: null,
            'server_db_database' => $_SERVER['DB_DATABASE'] ?? null,
            'environment_db_database' => $_ENV['DB_DATABASE'] ?? null,
            'current_url' => $request->fullUrl(),
            'script_filename' => $_SERVER['SCRIPT_FILENAME'] ?? null,
            'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? null,
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
            'applied_plan' => ['required', 'string', Rule::in($featureAccess->acceptedPlanCodes())],
            'store_mode' => ['nullable', 'string', Rule::in($featureAccess->acceptedStoreModes())],
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

    protected function handleSnapshotRequest(
        Request $request,
        CoreMarketRuntimeSnapshotService $runtimeSnapshotService,
        string $mode
    ): JsonResponse {
        try {
            $payload = $this->validatedPayload($request);
            $runtime = $mode === 'apply'
                ? $runtimeSnapshotService->apply($payload)
                : $runtimeSnapshotService->preview($payload);

            return response()->json([
                'result' => true,
                'mode' => $mode,
                'runtime' => $runtime,
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $diagnostics = method_exists($runtimeSnapshotService, 'storageDiagnostics')
                ? $runtimeSnapshotService->storageDiagnostics()
                : [];

            Log::error('CoreMarket runtime snapshot receiver failed.', [
                'mode' => $mode,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'store_mode' => $request->input('store_mode'),
                'applied_plan' => $request->input('applied_plan'),
                'status' => $request->input('status'),
                'feature_keys' => array_keys((array) $request->input('features', [])),
                'limit_keys' => array_keys((array) $request->input('limits', [])),
                'storage_diagnostics' => $diagnostics,
            ]);

            return response()->json([
                'message' => 'CoreMarket runtime receiver failed. Check CoreMarket logs.',
            ], 500);
        }
    }
}
