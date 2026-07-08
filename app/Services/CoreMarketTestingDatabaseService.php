<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class CoreMarketTestingDatabaseService
{
    public function testingDatabaseConfig(): array
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
            $defaultConnection = config('database.default');
            $config['database'] = config("database.connections.{$defaultConnection}.database");
        }

        return $config;
    }

    public function runtimeDatabaseName(): ?string
    {
        $defaultConnection = config('database.default');

        return config("database.connections.{$defaultConnection}.database");
    }

    public function baselineSqlPath(): string
    {
        return base_path('database/base/coremarket.sql');
    }

    public function mysqlBinaryPath(): ?string
    {
        $candidates = [
            env('COREMARKET_MYSQL_BIN'),
            dirname(dirname(base_path())) . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mysql.exe',
            dirname(dirname(base_path())) . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mysql',
        ];

        foreach ($candidates as $candidate) {
            if ($candidate && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function inspectDatabase(array $config): array
    {
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

        $criticalTables = ['business_settings', 'users', 'languages', 'currencies', 'uploads', 'products', 'orders'];
        $legacyCommandTables = ['shops', 'currencies', 'languages', 'roles', 'business_settings'];

        return [
            'table_count' => count($connection->select('SHOW TABLES')),
            'critical_tables' => collect($criticalTables)->map(function (string $table) use ($connection) {
                return [
                    'table' => $table,
                    'exists' => $this->tableExists($connection, $table),
                ];
            })->all(),
            'legacy_command_tables' => collect($legacyCommandTables)->map(function (string $table) use ($connection) {
                return [
                    'table' => $table,
                    'exists' => $this->tableExists($connection, $table),
                ];
            })->all(),
        ];
    }

    public function restorePlan(): array
    {
        $testing = $this->testingDatabaseConfig();
        $runtimeDatabase = $this->runtimeDatabaseName();
        $baselinePath = $this->baselineSqlPath();
        $mysqlBinary = $this->mysqlBinaryPath();

        return [
            'testing_database' => $testing['database'],
            'runtime_database' => $runtimeDatabase,
            'host' => $testing['host'] ?? config('database.connections.mysql.host'),
            'port' => $testing['port'] ?? config('database.connections.mysql.port'),
            'username' => $testing['username'] ?? config('database.connections.mysql.username'),
            'password' => $testing['password'] ?? config('database.connections.mysql.password'),
            'baseline_path' => $baselinePath,
            'baseline_exists' => is_file($baselinePath),
            'baseline_size' => is_file($baselinePath) ? filesize($baselinePath) : null,
            'mysql_binary' => $mysqlBinary,
        ];
    }

    public function validateRestorePlan(array $plan): array
    {
        $errors = [];

        if (blank($plan['testing_database'])) {
            $errors[] = 'Testing database name could not be resolved.';
        }

        if (! str_contains((string) $plan['testing_database'], '_testing')) {
            $errors[] = 'Refusing to restore a database that does not contain _testing in its name.';
        }

        if ($plan['testing_database'] === $plan['runtime_database']) {
            $errors[] = 'Refusing to restore because the target database matches the runtime database.';
        }

        if (! $plan['baseline_exists']) {
            $errors[] = 'The private baseline SQL file does not exist.';
        }

        if (! $plan['mysql_binary']) {
            $errors[] = 'The MySQL client binary could not be found.';
        }

        return $errors;
    }

    public function restoreTestingDatabase(array $plan): array
    {
        $baseArgs = [
            $plan['mysql_binary'],
            '--protocol=TCP',
            '-h' . $plan['host'],
            '-P' . $plan['port'],
            '-u' . $plan['username'],
        ];

        if (filled($plan['password'])) {
            $baseArgs[] = '--password=' . $plan['password'];
        }

        $createDatabaseProcess = new Process(array_merge($baseArgs, [
            '-e',
            sprintf(
                'DROP DATABASE IF EXISTS `%1$s`; CREATE DATABASE `%1$s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;',
                $plan['testing_database']
            ),
        ]));
        $createDatabaseProcess->setTimeout(300);
        $createDatabaseProcess->mustRun();

        $importProcess = new Process(array_merge($baseArgs, [$plan['testing_database']]));
        $importProcess->setInput(file_get_contents($plan['baseline_path']));
        $importProcess->setTimeout(900);
        $importProcess->mustRun();

        return $this->inspectDatabase([
            'database' => $plan['testing_database'],
            'host' => $plan['host'],
            'port' => $plan['port'],
            'username' => $plan['username'],
            'password' => $plan['password'],
        ]);
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
