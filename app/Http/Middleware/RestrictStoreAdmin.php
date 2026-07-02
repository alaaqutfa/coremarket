<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Str;

class RestrictStoreAdmin
{
    public function handle($request, Closure $next)
    {
        $user = $request->user();
        $roleName = config('coremarket.access.store_admin_role', 'store_admin');

        if (
            !$user ||
            $user->user_type !== 'staff' ||
            !method_exists($user, 'hasRole') ||
            !$user->hasRole($roleName)
        ) {
            return $next($request);
        }

        $route = $request->route();
        $routeName = $route ? ($route->getName() ?? '') : '';
        $routeUri = $route ? trim($route->uri(), '/') : trim($request->path(), '/');

        foreach (config('coremarket.access.store_admin_blocked_route_names', []) as $pattern) {
            if ($routeName !== '' && Str::is($pattern, $routeName)) {
                abort(403);
            }
        }

        foreach (config('coremarket.access.store_admin_blocked_route_uris', []) as $pattern) {
            if ($routeUri !== '' && Str::is($pattern, $routeUri)) {
                abort(403);
            }
        }

        return $next($request);
    }
}
