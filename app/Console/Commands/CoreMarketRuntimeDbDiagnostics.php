<?php

namespace App\Console\Commands;

use App\Services\CoreMarketRuntimeSnapshotService;
use Illuminate\Console\Command;

class CoreMarketRuntimeDbDiagnostics extends Command
{
    protected $signature = 'coremarket:runtime-db-diagnostics';

    protected $description = 'Inspect the CoreMarket runtime snapshot database context without writing data';

    public function handle(CoreMarketRuntimeSnapshotService $runtimeSnapshotService): int
    {
        $diagnostics = $runtimeSnapshotService->storageDiagnostics();

        $this->info('CoreMarket runtime snapshot database diagnostics');
        $this->newLine();
        $this->table(
            ['Field', 'Value'],
            [
                ['App environment', $diagnostics['app_environment'] ?? app()->environment()],
                ['Config cached', ($diagnostics['config_cached'] ?? false) ? 'yes' : 'no'],
                ['Default connection', $diagnostics['default_connection_name'] ?? '[unknown]'],
                ['Default database', $diagnostics['default_database_name'] ?? '[unknown]'],
                ['Runtime snapshot connection', $diagnostics['runtime_connection_name'] ?? '[unknown]'],
                ['Runtime snapshot database', $diagnostics['runtime_database_name'] ?? '[unknown]'],
                ['business_settings exists', ($diagnostics['has_business_settings_table'] ?? false) ? 'yes' : 'no'],
                ['business_settings count', $diagnostics['business_settings_count'] ?? '[unavailable]'],
                ['Forbidden DB detected', ($diagnostics['forbidden_database_detected'] ?? false) ? 'yes' : 'no'],
                ['Runtime status', $runtimeSnapshotService->persistedStatus() ?? '[missing]'],
                ['Applied plan', $runtimeSnapshotService->persistedPlanCode() ?? '[missing]'],
                ['Store mode', $runtimeSnapshotService->persistedStoreMode() ?? '[missing]'],
            ]
        );

        $this->line('Notes');
        $this->line('- This command is read-only and does not modify CoreMarket data.');
        $this->line('- No sync token or database credentials are shown here.');

        return self::SUCCESS;
    }
}
