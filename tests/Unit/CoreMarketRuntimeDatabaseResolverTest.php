<?php

namespace Tests\Unit;

use App\Services\CoreMarketRuntimeDatabaseResolver;
use Tests\TestCase;

class CoreMarketRuntimeDatabaseResolverTest extends TestCase
{
    public function test_runtime_connection_defaults_to_coremarket_runtime_not_ambient_db_database(): void
    {
        config()->set('database.connections.coremarket_runtime.database', 'coremarket_runtime');
        config()->set('database.connections.mysql.database', 'corepilotos');
        config()->set('coremarket.runtime_snapshot.connection', 'coremarket_runtime');

        $resolver = app(CoreMarketRuntimeDatabaseResolver::class);

        $this->assertSame('coremarket_runtime', $resolver->runtimeConnectionName());
        $this->assertSame('coremarket_runtime', config('database.connections.coremarket_runtime.database'));
    }

    public function test_corepilotos_is_always_an_unsafe_runtime_database(): void
    {
        $resolver = app(CoreMarketRuntimeDatabaseResolver::class);

        $this->assertTrue($resolver->isForbiddenDatabase('corepilotos'));
        $this->assertTrue($resolver->isForbiddenDatabase(null));
        $this->assertFalse($resolver->isForbiddenDatabase('coremarket_runtime'));
    }
}
