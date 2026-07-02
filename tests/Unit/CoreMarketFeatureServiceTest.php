<?php

namespace Tests\Unit;

use App\Http\Middleware\EnsureCoreMarketFeature;
use App\Services\CoreMarketFeatureService;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CoreMarketFeatureServiceTest extends TestCase
{
    public function test_feature_helper_returns_false_for_disabled_pos(): void
    {
        $this->assertFalse(coremarket_feature_enabled('pos_enabled'));
    }

    public function test_feature_helper_returns_false_for_disabled_payment_gateway(): void
    {
        $this->assertFalse(coremarket_feature_enabled('payment_gateway_enabled'));
    }

    public function test_feature_helper_returns_false_for_disabled_vendor_mode(): void
    {
        $this->assertFalse(coremarket_feature_enabled('vendor_mode_enabled'));
    }

    public function test_plan_code_and_limit_placeholders_are_available(): void
    {
        /** @var CoreMarketFeatureService $service */
        $service = app(CoreMarketFeatureService::class);

        $this->assertSame('ecommerce_starter', $service->planCode());
        $this->assertSame(50, $service->limit('products_limit'));
        $this->assertSame(300, $service->limit('monthly_orders_limit'));
    }

    public function test_disabled_feature_blocks_route_without_bypass(): void
    {
        $middleware = new EnsureCoreMarketFeature();
        $request = $this->makeRequest('admin/pos', null);

        try {
            $middleware->handle($request, fn () => new Response('ok', 200), 'pos_enabled');
            $this->fail('Expected disabled feature route to be blocked.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_platform_owner_can_bypass_disabled_feature_when_allowed(): void
    {
        $middleware = new EnsureCoreMarketFeature();
        $request = $this->makeRequest('admin/pos', $this->makeAdminUser());

        $response = $middleware->handle($request, fn () => new Response('ok', 200), 'pos_enabled', '1');

        $this->assertSame(200, $response->getStatusCode());
    }

    private function makeRequest(string $routeUri, ?object $user): Request
    {
        $request = Request::create('/' . ltrim($routeUri, '/'), 'GET');
        $route = new Route(['GET'], $routeUri, fn () => new Response('ok', 200));

        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function makeAdminUser(): object
    {
        return new class {
            public string $user_type = 'admin';

            public function hasRole(string $role): bool
            {
                return $role === 'Super Admin';
            }
        };
    }
}
