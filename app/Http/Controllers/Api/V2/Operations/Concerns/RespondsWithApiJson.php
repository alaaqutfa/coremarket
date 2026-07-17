<?php

namespace App\Http\Controllers\Api\V2\Operations\Concerns;

use App\Services\CoreMarketFeatureAccessService;
use Illuminate\Http\JsonResponse;

trait RespondsWithApiJson
{
    public function success($data = [], $message = null, $status = 200)
    {
        return response()->json([
            'ok' => true,
            'data' => $data,
            'message' => $message,
        ], $status);
    }

    protected function error(string $message, array $errors = [], int $status = 400): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'message' => $message,
            'errors' => (object) $errors,
        ], $status);
    }

    protected function validationError(array $errors, string $message = 'Validation failed'): JsonResponse
    {
        return $this->error($message, $errors, 422);
    }

    protected function conflict(string $message, array $errors = []): JsonResponse
    {
        return $this->error($message, $errors, 409);
    }

    protected function ensureFeaturesEnabled(): void
    {
        $features = app(CoreMarketFeatureAccessService::class);

        // Features are enforced for every caller, including admin API tokens.
        if (! $features->enabled('pos') || ! $features->enabled('cashbox_shifts')) {
            abort(404);
        }
    }

    protected function ensurePermission(string $permission): void
    {
        $user = request()->user();

        if (! $user || ($user->user_type !== 'admin' && ! $user->can($permission))) {
            abort(403);
        }
    }

    protected function ensureAnyPermission(array $permissions): void
    {
        $user = request()->user();

        if (! $user || ($user->user_type !== 'admin' && ! $user->hasAnyPermission($permissions))) {
            abort(403);
        }
    }
}
