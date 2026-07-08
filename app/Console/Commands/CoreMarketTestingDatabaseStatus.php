<?php

namespace App\Console\Commands;

use App\Services\CoreMarketTestingDatabaseService;
use Illuminate\Console\Command;

class CoreMarketTestingDatabaseStatus extends Command
{
    protected $signature = 'coremarket:testing-database-status';

    protected $description = 'Inspect the configured CoreMarket testing database without writing data';

    public function handle(CoreMarketTestingDatabaseService $service): int
    {
        $config = $service->testingDatabaseConfig();

        if (! $config['database']) {
            $this->warn('Testing database name could not be resolved from .env.testing or the current environment.');
            return self::SUCCESS;
        }

        try {
            $inspection = $service->inspectDatabase($config);
        } catch (\Throwable $e) {
            $this->error('Unable to inspect the testing database: ' . $e->getMessage());
            return self::SUCCESS;
        }

        $this->info('CoreMarket testing database status');
        $this->newLine();
        $this->table(
            ['Field', 'Value'],
            [
                ['Testing DB', $config['database']],
                ['Host', $config['host'] ?? '[default]'],
                ['Table count', $inspection['table_count']],
                ['Legacy command tests ready', collect($inspection['legacy_command_tables'])->every(fn (array $row) => $row['exists']) ? 'yes' : 'no'],
            ]
        );

        $this->line('Critical baseline tables');
        $this->table(
            ['Status', 'Table'],
            collect($inspection['critical_tables'])->map(fn (array $row) => [$row['exists'] ? 'PASS' : 'WARN', $row['table']])->all()
        );

        $this->line('Legacy command test tables');
        $this->table(
            ['Status', 'Table'],
            collect($inspection['legacy_command_tables'])->map(fn (array $row) => [$row['exists'] ? 'PASS' : 'WARN', $row['table']])->all()
        );

        $this->line('Notes');
        $this->line('- CoreMarket legacy command tests require a testing database imported from the private baseline SQL when these tables are missing.');
        $this->line('- This command is read-only and does not modify the testing database.');

        return self::SUCCESS;
    }
}
