<?php

namespace App\Http\Middleware;

use App\Services\CoreMarketFeatureService;
use Closure;

class EnsureCoreMarketFeature
{
    public function handle($request, Closure $next, string $feature, string $allowPlatformOwnerBypass = '0')
    {
        if ($this->shouldBypass($request->user(), $allowPlatformOwnerBypass === '1')) {
            return $next($request);
        }

        /** @var CoreMarketFeatureService $features */
        $features = app(CoreMarketFeatureService::class);

        if (! $features->enabled($feature)) {
            abort(404);
        }

        return $next($request);
    }

    protected function shouldBypass($user, bool $allowPlatformOwnerBypass): bool
    {
        if (! $allowPlatformOwnerBypass || ! $user) {
            return false;
        }

        if ($user->user_type === 'admin') {
            return true;
        }

        return method_exists($user, 'hasRole') && $user->hasRole('Super Admin');
    }
}
