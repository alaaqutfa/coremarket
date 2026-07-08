<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CoreMarketTestingDatabaseStatus extends Command
{
    protected $signature = 'coremarket:testing-database-status';

    protected $description = 'Inspect the configured CoreMarket testing database without writing data';

    public function handle(): int
    {
        $config = $this->testingDatabaseConfig();

        if (! $config['database']) {
            $this->warn('Testing database name could not be resolved from .env.testing or the current environment.');
            return self::SUCCESS;
        }

        $connectionName = 'coremarket_testing_inspector';
        config([
            "database.connections.{$connectionName}" => array_merge(
                config('database.connections.mysql', []),
                [
                    'database' => $config['database'],
                    'host' => $config['host'] ?? config('database.connections.mysql.host'),
                    'port' => $config['port'] ?? config('database.connections.mysql.port'),
                    'username' => $config['username'] ?? config('database.connections.mysql.username'),
                    'password' => $config['password'] ?? config('database.connections.mysql.password'),
                ]
            ),
        ]);

        DB::purge($connectionName);
        $connection = DB::connection($connectionName);

        try {
            $tables = $connection->select('SHOW TABLES');
            $tableCount = count($tables);
        } catch (\Throwable $e) {
            $this->error('Unable to inspect the testing database: ' . $e->getMessage());
            return self::SUCCESS;
        }

        $criticalTables = ['business_settings', 'users', 'languages', 'currencies', 'uploads', 'products', 'orders'];
        $legacyCommandTables = ['shops', 'currencies', 'languages', 'roles', 'business_settings'];

        $criticalStatus = collect($criticalTables)->map(function (string $table) use ($connection) {
            return [
                'table' => $table,
                'exists' => $this->tableExists($connection, $table),
            ];
        });

        $legacyStatus = collect($legacyCommandTables)->map(function (string $table) use ($connection) {
            return [
                'table' => $table,
                'exists' => $this->tableExists($connection, $table),
            ];
        });

        $this->info('CoreMarket testing database status');
        $this->newLine();
        $this->table(
            ['Field', 'Value'],
            [
                ['Testing DB', $config['database']],
                ['Host', $config['host'] ?? '[default]'],
                ['Table count', $tableCount],
                ['Legacy command tests ready', $legacyStatus->every(fn (array $row) => $row['exists']) ? 'yes' : 'no'],
            ]
        );

        $this->line('Critical baseline tables');
        $this->table(
            ['Status', 'Table'],
            $criticalStatus->map(fn (array $row) => [$row['exists'] ? 'PASS' : 'WARN', $row['table']])->all()
        );

        $this->line('Legacy command test tables');
        $this->table(
            ['Status', 'Table'],
            $legacyStatus->map(fn (array $row) => [$row['exists'] ? 'PASS' : 'WARN', $row['table']])->all()
        );

        $this->line('Notes');
        $this->line('- CoreMarket legacy command tests require a testing database imported from the private baseline SQL when these tables are missing.');
        $this->line('- This command is read-only and does not modify the testing database.');

        return self::SUCCESS;
    }

    protected function testingDatabaseConfig(): array
    {
        $config = [
            'database' => null,
            'host' => null,
            'port' => null,
            'username' => null,
            'password' => null,
        ];

        $envTesting = base_path('.env.testing');

        if (is_file($envTesting)) {
            foreach (file($envTesting, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);

                if ($line === '' || Str::startsWith($line, '#') || ! str_contains($line, '=')) {
                    continue;
                }

                [$key, $value] = explode('=', $line, 2);
                $value = trim($value, " \t\n\r\0\x0B\"'");

                if ($key === 'DB_DATABASE') {
                    $config['database'] = $value;
                } elseif ($key === 'DB_HOST') {
                    $config['host'] = $value;
                } elseif ($key === 'DB_PORT') {
                    $config['port'] = $value;
                } elseif ($key === 'DB_USERNAME') {
                    $config['username'] = $value;
                } elseif ($key === 'DB_PASSWORD') {
                    $config['password'] = $value;
                }
            }
        }

        if (! $config['database']) {
            $config['database'] = env('DB_DATABASE');
        }

        return $config;
    }

    protected function tableExists($connection, string $table): bool
    {
        try {
            return $connection->getSchemaBuilder()->hasTable($table);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
