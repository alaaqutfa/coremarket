<?php

namespace App\Http\Middleware;

use App\Services\CoreMarketLicenseService;
use Closure;

class EnsureCoreMarketLicense
{
    public function handle($request, Closure $next, string $ability = 'manage_store')
    {
        /** @var CoreMarketLicenseService $license */
        $license = app(CoreMarketLicenseService::class);
        $user = $request->user();

        $allowed = $ability === 'accept_orders'
            ? $license->canAcceptOrders($user)
            : $license->canManageStore($user);

        if ($allowed) {
            return $next($request);
        }

        $message = $ability === 'accept_orders'
            ? $license->orderLockMessage()
            : $license->managementLockMessage();

        if ($request->expectsJson() || $request->ajax() || $request->is('api/*')) {
            return response()->json([
                'result' => false,
                'message' => translate($message),
            ], 423);
        }

        flash(translate($message))->warning();

        if ($ability === 'accept_orders' && app('router')->has('checkout')) {
            return redirect()->route('checkout');
        }

        if ($user && ($user->user_type ?? null) === 'staff' && app('router')->has('admin.dashboard')) {
            return redirect()->route('admin.dashboard');
        }

        return redirect()->back();
    }
}
