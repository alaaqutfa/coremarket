<?php

namespace App\Console\Commands;

use App\Services\CoreMarketTestingDatabaseService;
use Illuminate\Console\Command;

class CoreMarketRestoreTestingDatabase extends Command
{
    protected $signature = 'coremarket:restore-testing-database
                            {--dry-run : Preview the testing database restore workflow without writing data}
                            {--apply : Restore the testing database from the selected private baseline SQL}
                            {--from-clean-baseline : Use database/base/coremarket.sql instead of the default demo/testing baseline}
                            {--confirm-testing-db-restore : Confirm that the testing database may be dropped and recreated from the private baseline}';

    protected $description = 'Restore the CoreMarket testing database from the private demo/testing or clean baseline SQL file';

    public function handle(CoreMarketTestingDatabaseService $service): int
    {
        $applyRequested = (bool) $this->option('apply');
        $plan = $service->restorePlan((bool) $this->option('from-clean-baseline'));
        $validationErrors = $service->validateRestorePlan($plan);

        $this->info('CoreMarket testing database restore plan');
        $this->newLine();
        $this->table(
            ['Field', 'Value'],
            [
                ['Mode', $applyRequested ? 'apply' : 'dry-run'],
                ['Runtime DB', $plan['runtime_database'] ?? '[unknown]'],
                ['Testing DB', $plan['testing_database'] ?? '[unknown]'],
                ['Host', $plan['host'] ?? '[default]'],
                ['Port', $plan['port'] ?? '[default]'],
                ['Baseline source', $plan['baseline_source_label']],
                ['Baseline SQL', $plan['baseline_path']],
                ['Baseline exists', $plan['baseline_exists'] ? 'yes' : 'no'],
                ['Baseline size', $plan['baseline_size'] ?? '[missing]'],
                ['MySQL client', $plan['mysql_binary'] ?? '[missing]'],
                ['Confirmed', $this->option('confirm-testing-db-restore') ? 'yes' : 'no'],
            ]
        );

        $this->line('Notes');
        $this->line('- This workflow may drop and recreate only the testing database.');
        $this->line('- It refuses to run if the target database does not contain _testing or matches the runtime database.');
        $this->line('- It imports the selected private baseline SQL without touching coremarket_runtime.');

        if (! $applyRequested) {
            $this->info('Dry-run complete. No database changes were made.');
            return self::SUCCESS;
        }

        if (! $this->option('confirm-testing-db-restore')) {
            $validationErrors[] = 'Apply mode requires --confirm-testing-db-restore.';
        }

        if (! empty($validationErrors)) {
            $this->warn('Apply mode was requested, but the safety requirements were not met.');
            foreach ($validationErrors as $error) {
                $this->line('- ' . $error);
            }
            $this->warn('No database changes were made.');
            return self::SUCCESS;
        }

        $this->info('Restoring the testing database from the selected private baseline SQL...');
        $inspection = $service->restoreTestingDatabase($plan);

        $this->table(
            ['Field', 'Value'],
            [
                ['Testing DB', $plan['testing_database']],
                ['Table count', $inspection['table_count']],
                ['Legacy command tests ready', collect($inspection['legacy_command_tables'])->every(fn (array $row) => $row['exists']) ? 'yes' : 'no'],
            ]
        );

        $this->line('Critical baseline tables after restore');
        $this->table(
            ['Status', 'Table'],
            collect($inspection['critical_tables'])->map(fn (array $row) => [$row['exists'] ? 'PASS' : 'WARN', $row['table']])->all()
        );

        $this->line('Legacy command test tables after restore');
        $this->table(
            ['Status', 'Table'],
            collect($inspection['legacy_command_tables'])->map(fn (array $row) => [$row['exists'] ? 'PASS' : 'WARN', $row['table']])->all()
        );

        $this->info('Testing database restore complete.');

        return self::SUCCESS;
    }
}
