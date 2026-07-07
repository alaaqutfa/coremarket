<?php

namespace Tests\Unit;

use App\Http\Middleware\RestrictStoreAdmin;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RestrictStoreAdminMiddlewareTest extends TestCase
{
    public function test_admin_user_bypasses_store_admin_restrictions(): void
    {
        $middleware = new RestrictStoreAdmin();
        $request = $this->makeRequest('payment_method.index', 'admin/payment-method', $this->makeAdminUser());

        $response = $middleware->handle($request, fn () => new Response('ok', 200));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_store_admin_can_access_allowed_dashboard_route(): void
    {
        $middleware = new RestrictStoreAdmin();
        $request = $this->makeRequest('admin.dashboard', 'admin', $this->makeStoreAdminUser());

        $response = $middleware->handle($request, fn () => new Response('ok', 200));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_store_admin_cannot_access_payment_settings(): void
    {
        $this->assertStoreAdminRouteBlocked('payment_method.index', 'admin/payment-method');
    }

    public function test_store_admin_cannot_access_update_routes(): void
    {
        $this->assertStoreAdminRouteBlocked('update.step1', 'update/step1');
    }

    public function test_store_admin_cannot_access_pos_routes(): void
    {
        $this->assertStoreAdminRouteBlocked('poin-of-sales.index', 'admin/pos');
    }

    public function test_store_admin_cannot_access_role_management(): void
    {
        $this->assertStoreAdminRouteBlocked('roles.index', 'admin/roles');
    }

    public function test_store_admin_cannot_access_activation_control_center(): void
    {
        $this->assertStoreAdminRouteBlocked('activation.index', 'admin/activation');
    }

    private function assertStoreAdminRouteBlocked(string $routeName, string $routeUri): void
    {
        $middleware = new RestrictStoreAdmin();
        $request = $this->makeRequest($routeName, $routeUri, $this->makeStoreAdminUser());

        try {
            $middleware->handle($request, fn () => new Response('ok', 200));
            $this->fail('Expected store_admin route to be blocked.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    private function makeRequest(string $routeName, string $routeUri, object $user): Request
    {
        $request = Request::create('/' . ltrim($routeUri, '/'), 'GET');
        $route = new Route(['GET'], $routeUri, fn () => new Response('ok', 200));
        $route->name($routeName);

        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function makeStoreAdminUser(): object
    {
        return new class {
            public string $user_type = 'staff';

            public function hasRole(string $role): bool
            {
                return $role === config('coremarket.access.store_admin_role', 'store_admin');
            }
        };
    }

    private function makeAdminUser(): object
    {
        return new class {
            public string $user_type = 'admin';

            public function hasRole(string $role): bool
            {
                return false;
            }
        };
    }
}
