<?php

namespace Tests\Unit;

use RuntimeException;
use Tests\Support\CoreMarketTestingDatabaseGuard;
use Tests\TestCase;

class CoreMarketTestingDatabaseGuardTest extends TestCase
{
    public function test_testing_database_names_are_allowed(): void
    {
        CoreMarketTestingDatabaseGuard::assertSafe('coremarket_testing');
        CoreMarketTestingDatabaseGuard::assertSafe('tenant_testing');

        $this->assertTrue(true);
    }

    public function test_runtime_database_names_are_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to run tests against non-testing CoreMarket database');

        CoreMarketTestingDatabaseGuard::assertSafe('coremarket_runtime');
    }
}
