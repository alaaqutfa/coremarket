<?php

namespace App\Console\Commands;

use App\Services\CoreMarketStorefrontCleanupService;
use Illuminate\Console\Command;

class CoreMarketCleanStorefrontSettings extends Command
{
    protected $signature = 'coremarket:clean-storefront-settings
                            {--dry-run : Preview the storefront cleanup plan without writing data}
                            {--apply : Apply the allowed storefront business settings after explicit confirmation}
                            {--confirm-storefront-cleanup : Confirm that the safe storefront cleanup should write business settings}';

    protected $description = 'Audit and safely clean CoreMarket storefront business settings without touching commercial data';

    public function handle(CoreMarketStorefrontCleanupService $cleanupService): int
    {
        $applyRequested = (bool) $this->option('apply');

        $plan = $cleanupService->buildPlan([
            'apply' => $applyRequested,
            'confirm_storefront_cleanup' => (bool) $this->option('confirm-storefront-cleanup'),
        ]);

        $validationErrors = $cleanupService->validateApplyRequirements($plan);

        $this->info('CoreMarket storefront settings cleanup plan');
        $this->newLine();

        $this->table(
            ['Field', 'Value'],
            [
                ['Mode', $plan['apply_requested'] ? 'apply' : 'dry-run'],
                ['Confirmed', $plan['confirmed'] ? 'yes' : 'no'],
                ['Settings in scope', count($plan['settings'])],
            ]
        );

        $this->line('Business settings preview');
        $this->table(
            ['Type', 'Lang', 'Current Value', 'Target Value'],
            collect($plan['settings'])->map(function (array $setting) {
                return [
                    $setting['type'],
                    $setting['lang'] ?? '[default]',
                    $setting['current_value'] ?? '[not set]',
                    $setting['target_value'] === '' ? '[blank]' : ($setting['target_value'] ?? '[not set]'),
                ];
            })->all()
        );

        $this->line('Notes');
        foreach ($plan['notes'] as $note) {
            $this->line('- ' . $note);
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

        $this->info('Applying allowed storefront business_settings only...');
        $applied = $cleanupService->applyCleanup($plan['settings']);

        $this->table(
            ['Type', 'Lang', 'Status', 'Previous Value', 'Applied Value'],
            collect($applied)->map(function (array $setting) {
                return [
                    $setting['type'],
                    $setting['lang'] ?? '[default]',
                    $setting['status'],
                    $setting['previous'] ?? '[not set]',
                    $setting['value'] === '' ? '[blank]' : ($setting['value'] ?? '[not set]'),
                ];
            })->all()
        );

        $this->info('Apply complete. Only the allowed storefront business_settings were updated.');

        return self::SUCCESS;
    }
}
