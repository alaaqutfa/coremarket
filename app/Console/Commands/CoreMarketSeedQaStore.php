<?php

namespace App\Console\Commands;

use App\Services\CoreMarketQaStoreSeedService;
use Illuminate\Console\Command;

class CoreMarketSeedQaStore extends Command
{
    protected $signature = 'coremarket:seed-qa-store
                            {--dry-run : Preview the QA local store seed without writing data}
                            {--apply : Create or update the QA local store data}
                            {--confirm-qa-seed : Confirm that the QA local store seed should write data}
                            {--password= : Optional local-only QA password override}';

    protected $description = 'Seed local-only CoreMarket QA storefront data for end-to-end COD order testing';

    public function handle(CoreMarketQaStoreSeedService $service): int
    {
        $applyRequested = (bool) $this->option('apply');

        $plan = $service->buildPlan([
            'apply' => $applyRequested,
            'confirm_qa_seed' => (bool) $this->option('confirm-qa-seed'),
            'password' => $this->option('password'),
        ]);

        $validationErrors = $service->validateApplyRequirements($plan);

        $this->info('CoreMarket QA store seed plan');
        $this->newLine();

        $this->table(
            ['Field', 'Value'],
            [
                ['Mode', $plan['apply_requested'] ? 'apply' : 'dry-run'],
                ['Confirmed', $plan['confirmed'] ? 'yes' : 'no'],
                ['Admin product owner', $plan['admin_user']?->email ?? '[missing]'],
                ['Store admin role', $plan['store_admin_role']?->name ?? '[missing]'],
                ['Local-only QA password', $plan['default_password']],
            ]
        );

        $this->line('QA resources');
        $this->table(
            ['Resource', 'Identifier', 'Action', 'Note'],
            collect($plan['resources'])->map(function (array $row) {
                return [
                    $row['resource'],
                    $row['identifier'],
                    $row['action'],
                    $row['note'],
                ];
            })->all()
        );

        $this->line('QA baseline settings');
        $this->table(
            ['Key', 'Current Value', 'Target Value', 'Action'],
            collect($plan['settings'])->map(function (array $row) {
                return [
                    $row['key'],
                    $row['current_value'] ?? '[not set]',
                    $row['target_value'],
                    $row['action'],
                ];
            })->all()
        );

        $this->line('Checkout QA flow');
        foreach ($plan['flow'] as $step) {
            $this->line('- ' . $step);
        }

        if (! $plan['apply_requested']) {
            $this->info('Dry-run complete. No database changes were made.');
            return self::SUCCESS;
        }

        if (! empty($validationErrors)) {
            $this->warn('Apply mode was requested, but the safety requirements were not met.');
            foreach ($validationErrors as $error) {
                $this->line('- ' . $error);
            }
            $this->warn('No database changes were made.');
            return self::SUCCESS;
        }

        $results = $service->applySeed($plan);

        $this->info('Applying local-only QA store seed...');
        $this->table(
            ['Resource', 'Identifier', 'Status'],
            collect($results)->map(function (array $row) {
                return [
                    $row['resource'],
                    $row['identifier'],
                    $row['status'],
                ];
            })->all()
        );

        $this->info('Apply complete. QA local store data is ready.');

        return self::SUCCESS;
    }
}
