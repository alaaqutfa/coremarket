<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureCorePilotSyncToken
{
    public function handle(Request $request, Closure $next)
    {
        $expected = (string) config('coremarket.runtime_sync.token');
        $provided = $request->header(config('coremarket.runtime_sync.header', 'X-CorePilot-Sync-Token'));

        if (! $provided) {
            $authorization = (string) $request->header('Authorization');
            if (str_starts_with($authorization, 'Bearer ')) {
                $provided = substr($authorization, 7);
            }
        }

        if ($expected === '' || $provided === null || $provided === '') {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 401);
        }

        if (! hash_equals($expected, $provided)) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        return $next($request);
    }
}
