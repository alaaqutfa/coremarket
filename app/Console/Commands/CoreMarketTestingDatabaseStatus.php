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
                ['Detected dataset', $inspection['detected_dataset']],
                ['Demo data present', $inspection['demo_data_present'] ? 'yes' : 'no'],
                ['Products count', $inspection['products_count'] ?? '[missing]'],
                ['Orders count', $inspection['orders_count'] ?? '[missing]'],
                ['Uploads count', $inspection['uploads_count'] ?? '[missing]'],
                ['Translations count', $inspection['translations_count'] ?? '[missing]'],
                ['Legacy branding warnings', $inspection['legacy_branding_findings_count']],
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

        if (! empty($inspection['demo_markers'])) {
            $this->line('Detected demo markers');
            $this->table(
                ['Marker', 'Value'],
                [
                    ['website_name', $inspection['demo_markers']['website_name'] ?? '[missing]'],
                    ['demo_categories', $inspection['demo_markers']['demo_categories'] ?? 0],
                    ['demo_products', $inspection['demo_markers']['demo_products'] ?? 0],
                ]
            );
        }

        if (($inspection['legacy_branding_findings_count'] ?? 0) > 0) {
            $this->line('Legacy branding findings');
            $this->table(
                ['Status', 'Term', 'Table', 'Column', 'Count'],
                collect($inspection['legacy_branding_findings'])->map(fn (array $row) => [
                    $row['status'],
                    $row['term'],
                    $row['table'],
                    $row['column'],
                    $row['count'],
                ])->all()
            );
        }

        $this->line('Notes');
        $this->line('- CoreMarket legacy command tests require a testing database imported from the private demo/testing or clean baseline SQL when these tables are missing.');
        $this->line('- This command is read-only and does not modify the testing database.');

        return self::SUCCESS;
    }
}
