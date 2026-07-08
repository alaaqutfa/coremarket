<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CoreMarketGuardDatabase extends Command
{
    protected $signature = 'coremarket:guard-database';

    protected $description = 'Run a read-only CoreMarket runtime database guard against incomplete local databases';

    public function handle(): int
    {
        $criticalTables = [
            'business_settings',
            'users',
            'products',
            'orders',
            'uploads',
            'currencies',
            'languages',
        ];

        $databaseName = DB::connection()->getDatabaseName();

        $this->info('CoreMarket runtime database guard');
        $this->newLine();
        $this->line('Database: ' . ($databaseName ?: '[unknown]'));

        $rows = [];
        $hasMissingTables = false;

        foreach ($criticalTables as $table) {
            $exists = Schema::hasTable($table);
            $hasMissingTables = $hasMissingTables || ! $exists;

            $rows[] = [
                $exists ? 'PASS' : 'FAIL',
                $table,
                $exists ? 'present' : 'missing',
            ];
        }

        $this->table(['Status', 'Table', 'Result'], $rows);

        if ($hasMissingTables) {
            $this->error('Critical CoreMarket runtime tables are missing. This database is not safe for local runtime.');
            $this->line('Use a full legacy/runtime database before opening the storefront or admin.');

            return self::FAILURE;
        }

        $this->info('Critical CoreMarket runtime tables are present. No database changes were made.');

        return self::SUCCESS;
    }
}
