<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        abort_unless(
            $user && $user->user_type === 'admin' && $user->hasRole('Super Admin'),
            403
        );

        return $next($request);
    }
}
