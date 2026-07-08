<?php

namespace Tests\Support;

use RuntimeException;

class CoreMarketTestingDatabaseGuard
{
    public static function assertSafe(?string $databaseName): void
    {
        $databaseName = strtolower(trim((string) $databaseName));

        if (str_contains($databaseName, '_testing')) {
            return;
        }

        if ($databaseName === 'coremarket_testing') {
            return;
        }

        if (in_array($databaseName, ['coremarket', 'core_market', 'coremarket_runtime', 'syrian_souq'], true) || $databaseName !== '') {
            throw new RuntimeException('Refusing to run tests against non-testing CoreMarket database');
        }

        throw new RuntimeException('Refusing to run tests against non-testing CoreMarket database');
    }
}
