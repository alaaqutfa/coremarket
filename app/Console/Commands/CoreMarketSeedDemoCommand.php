<?php

namespace App\Console\Commands;

use App\Services\Demo\CoreMarketDemoSeeder;
use Illuminate\Console\Command;

class CoreMarketSeedDemoCommand extends Command
{
    protected $signature = 'coremarket:seed-demo
                            {--dry-run : Preview the protected demo seed without writing data}
                            {--apply : Apply the protected demo seed}
                            {--confirm-demo-seed : Confirm that demo data may be written}
                            {--reset : Rebuild only recognized demo records}
                            {--with-samples=standard : Dataset size: standard or large}';

    protected $description = 'Plan and safely seed a dedicated CoreMarket demo database';

    public function handle(CoreMarketDemoSeeder $seeder): int
    {
        $plan = $seeder->buildPlan([
            'apply' => (bool) $this->option('apply'),
            'dry_run' => (bool) $this->option('dry-run'),
            'confirm_demo_seed' => (bool) $this->option('confirm-demo-seed'),
            'reset' => (bool) $this->option('reset'),
            'with_samples' => (string) $this->option('with-samples'),
        ]);
        $safetyErrors = $seeder->validateSafety($plan);

        $this->info('CoreMarket protected demo seed plan');
        $this->newLine();
        $this->table(['Field', 'Value'], [
            ['Database', $plan['database'] ?: '[unknown]'],
            ['Mode', $plan['mode']],
            ['Sample profile', $plan['sample_profile']],
            ['Reset requested', $plan['reset_requested'] ? 'yes' : 'no'],
            ['Database suffix guard', str_ends_with(strtolower($plan['database']), '_demo') ? 'PASS' : 'FAIL'],
            ['Explicit blocklist', in_array(strtolower($plan['database']), CoreMarketDemoSeeder::BLOCKED_DATABASES, true) ? 'FAIL' : 'PASS'],
        ]);

        $this->line('Planned records');
        $this->table(
            ['Area', 'Planned records', 'Idempotency marker'],
            collect($plan['planned_records'])->map(fn (array $row) => [
                $row['area'],
                $row['records'],
                $row['marker'],
            ])->all()
        );

        if ($safetyErrors !== []) {
            $this->error('Demo seed refused by the database safety guard.');
            foreach ($safetyErrors as $error) {
                $this->line('- ' . $error);
            }
            $this->warn('No database changes were made.');

            return self::FAILURE;
        }

        if (! $plan['apply_requested']) {
            $this->info('Dry-run complete. No database changes were made.');

            return self::SUCCESS;
        }

        $applyErrors = $seeder->validateApplyRequirements($plan);
        if ($applyErrors !== []) {
            $this->error('Apply mode was refused by the demo seed safety guard.');
            foreach ($applyErrors as $error) {
                $this->line('- ' . $error);
            }
            $this->warn('No database changes were made.');

            return self::FAILURE;
        }

        $result = $seeder->execute($plan);

        $this->line('Final summary');
        $this->table(['Status', 'Records written', 'Reset performed'], [[
            $result['status'],
            $result['records_written'],
            $result['reset_performed'] ? 'yes' : 'no',
        ]]);
        $this->table(['Area', 'Current rows'], collect($result['counts'])->map(
            fn (int $count, string $area) => [$area, $count]
        )->values()->all());
        $this->info('Demo seed completed safely.');

        return self::SUCCESS;
    }
}
