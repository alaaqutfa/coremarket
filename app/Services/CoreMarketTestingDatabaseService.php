<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class CoreMarketTestingDatabaseService
{
    protected array $criticalTables = ['business_settings', 'users', 'languages', 'currencies', 'uploads', 'products', 'orders'];

    protected array $legacyCommandTables = ['shops', 'currencies', 'languages', 'roles', 'business_settings'];

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

    public function cleanBaselineSqlPath(): string
    {
        return base_path('database/base/coremarket.sql');
    }

    public function demoBaselineSqlPath(): string
    {
        return base_path('database/base/coremarket_test.sql');
    }

    public function baselineSqlPath(bool $fromCleanBaseline = false): string
    {
        return $fromCleanBaseline
            ? $this->cleanBaselineSqlPath()
            : $this->demoBaselineSqlPath();
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
        $legacyBrandingFindings = $this->legacyBrandingFindingsForConnection($connection);
        $productsCount = $this->connectionTableCount($connection, 'products');
        $ordersCount = $this->connectionTableCount($connection, 'orders');
        $uploadsCount = $this->connectionTableCount($connection, 'uploads');
        $categoriesCount = $this->connectionTableCount($connection, 'categories');
        $translationsCount = $this->connectionTableCount($connection, 'translations');
        $demoData = $this->detectDemoData($connection);

        return [
            'table_count' => count($connection->select('SHOW TABLES')),
            'critical_tables' => collect($this->criticalTables)->map(function (string $table) use ($connection) {
                return [
                    'table' => $table,
                    'exists' => $this->tableExists($connection, $table),
                ];
            })->all(),
            'legacy_command_tables' => collect($this->legacyCommandTables)->map(function (string $table) use ($connection) {
                return [
                    'table' => $table,
                    'exists' => $this->tableExists($connection, $table),
                ];
            })->all(),
            'products_count' => $productsCount,
            'orders_count' => $ordersCount,
            'uploads_count' => $uploadsCount,
            'categories_count' => $categoriesCount,
            'translations_count' => $translationsCount,
            'demo_data_present' => $demoData['present'],
            'detected_dataset' => $demoData['dataset'],
            'demo_markers' => $demoData['markers'],
            'legacy_branding_findings_count' => count($legacyBrandingFindings),
            'legacy_branding_findings' => $legacyBrandingFindings,
        ];
    }

    public function restorePlan(bool $fromCleanBaseline = false): array
    {
        $testing = $this->testingDatabaseConfig();
        $runtimeDatabase = $this->runtimeDatabaseName();
        $baselinePath = $this->baselineSqlPath($fromCleanBaseline);
        $mysqlBinary = $this->mysqlBinaryPath();
        $source = $fromCleanBaseline ? 'clean_client_baseline' : 'demo_testing_baseline';
        $sourceLabel = $fromCleanBaseline ? 'clean client baseline' : 'demo/testing baseline';

        return [
            'testing_database' => $testing['database'],
            'runtime_database' => $runtimeDatabase,
            'host' => $testing['host'] ?? config('database.connections.mysql.host'),
            'port' => $testing['port'] ?? config('database.connections.mysql.port'),
            'username' => $testing['username'] ?? config('database.connections.mysql.username'),
            'password' => $testing['password'] ?? config('database.connections.mysql.password'),
            'baseline_source' => $source,
            'baseline_source_label' => $sourceLabel,
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

    protected function connectionTableCount($connection, string $table): ?int
    {
        if (! $this->tableExists($connection, $table)) {
            return null;
        }

        return $connection->table($table)->count();
    }

    protected function connectionHasColumn($connection, string $table, string $column): bool
    {
        try {
            return $connection->getSchemaBuilder()->hasColumn($table, $column);
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function detectDemoData($connection): array
    {
        $websiteName = null;

        if ($this->tableExists($connection, 'business_settings')) {
            $websiteName = $connection->table('business_settings')
                ->where('type', 'website_name')
                ->whereNull('lang')
                ->value('value');
        }

        $demoCategories = $this->tableExists($connection, 'categories') && $this->connectionHasColumn($connection, 'categories', 'name')
            ? $connection->table('categories')->where('name', 'like', 'Demo %')->count()
            : 0;
        $demoProducts = $this->tableExists($connection, 'products') && $this->connectionHasColumn($connection, 'products', 'name')
            ? $connection->table('products')->where('name', 'like', 'Demo %')->count()
            : 0;

        $present = $websiteName === 'CoreMarket Demo Store' || $demoCategories > 0 || $demoProducts > 0;

        return [
            'present' => $present,
            'dataset' => $present ? 'demo_baseline' : 'clean_or_custom_baseline',
            'markers' => [
                'website_name' => $websiteName,
                'demo_categories' => $demoCategories,
                'demo_products' => $demoProducts,
            ],
        ];
    }

    protected function legacyBrandingFindingsForConnection($connection): array
    {
        $warnings = collect();

        foreach (config('coremarket.clean_baseline.legacy_terms', []) as $term) {
            foreach (config('coremarket.clean_baseline.audit_targets', []) as $table => $columns) {
                if (! $this->tableExists($connection, $table)) {
                    continue;
                }

                foreach ($columns as $column) {
                    if (! $this->connectionHasColumn($connection, $table, $column)) {
                        continue;
                    }

                    $count = $connection->table($table)->where($column, 'like', '%' . $term . '%')->count();

                    if ($count < 1) {
                        continue;
                    }

                    $warnings->push([
                        'status' => 'WARN',
                        'term' => $term,
                        'table' => $table,
                        'column' => $column,
                        'count' => $count,
                    ]);
                }
            }
        }

        return $warnings->all();
    }
}
