<?php

namespace App\Console\Commands;

use App\Services\Demo\CoreMarketPilotDemoPreparer;
use Illuminate\Console\Command;
use Throwable;

class CoreMarketDemoPilotPrepare extends Command
{
    protected $signature = 'coremarket:demo-pilot-prepare
                            {--dry-run : Preview demo Pilot preparation without writing}
                            {--apply : Apply idempotent Pilot demo records}
                            {--confirm-demo-pilot : Confirm writes to the protected demo database}';

    protected $description = 'Prepare modern Pilot scenarios in a dedicated CoreMarket demo database';

    public function handle(CoreMarketPilotDemoPreparer $preparer): int
    {
        $plan = $preparer->plan(
            (bool) $this->option('apply'),
            (bool) $this->option('confirm-demo-pilot')
        );

        $this->table(['Field', 'Value'], [
            ['Database', $plan['database']],
            ['Mode', $plan['apply'] ? 'apply' : 'dry-run'],
            ['Demo database guard', $plan['safe'] ? 'PASS' : 'FAIL'],
        ]);
        $this->table(
            ['Area', 'Current rows'],
            collect($plan['records'])->map(fn ($count, $area) => [$area, $count])->values()->all()
        );

        if (! $plan['safe']) {
            $this->error('Pilot demo preparation refused: the active database is not an allowed demo database.');
            return self::FAILURE;
        }
        if (! $plan['apply']) {
            $this->info('Dry-run complete. No database changes were made.');
            return self::SUCCESS;
        }
        if (! $plan['confirmed']) {
            $this->error('Apply mode requires --confirm-demo-pilot. No database changes were made.');
            return self::FAILURE;
        }

        try {
            $records = $preparer->execute($plan);
        } catch (Throwable $exception) {
            $this->error('Pilot demo preparation failed: '.$exception->getMessage());
            return self::FAILURE;
        }

        $this->table(
            ['Area', 'Current rows'],
            collect($records)->map(fn ($count, $area) => [$area, $count])->values()->all()
        );
        $this->info('CoreMarket Pilot demo preparation completed safely.');

        return self::SUCCESS;
    }
}
