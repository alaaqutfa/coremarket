<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Tests\Support\CoreMarketTestingDatabaseGuard;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        if ($app->environment('testing')) {
            $defaultConnection = $app['config']->get('database.default');
            $databaseName = $app['config']->get("database.connections.{$defaultConnection}.database");

            CoreMarketTestingDatabaseGuard::assertSafe($databaseName);
        }

        return $app;
    }
}
