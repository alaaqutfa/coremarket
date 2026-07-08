<?php

namespace App\Console\Commands;

use App\Services\CoreMarketCleanBaselineService;
use Illuminate\Console\Command;

class CoreMarketCleanBaseline extends Command
{
    protected $signature = 'coremarket:clean-baseline
                            {--dry-run : Preview the clean baseline workflow without writing data}
                            {--apply : Apply the allowed baseline business settings after explicit confirmation}
                            {--confirm-clean-baseline : Confirm that the clean baseline workflow may update business settings}';

    protected $description = 'Neutralize CoreMarket baseline branding and unsafe baseline flags without deleting data';

    public function handle(CoreMarketCleanBaselineService $service): int
    {
        $applyRequested = (bool) $this->option('apply');

        $plan = $service->buildPlan([
            'apply' => $applyRequested,
            'confirm_clean_baseline' => (bool) $this->option('confirm-clean-baseline'),
        ]);

        $validationErrors = $service->validateApplyRequirements($plan);

        $this->info('CoreMarket clean baseline plan');
        $this->newLine();

        $this->table(
            ['Field', 'Value'],
            [
                ['Database', $plan['database'] ?: '[unknown]'],
                ['Table count', $plan['table_count']],
                ['Mode', $plan['apply_requested'] ? 'apply' : 'dry-run'],
                ['Confirmed', $plan['confirmed'] ? 'yes' : 'no'],
                ['Target language', $plan['target_language']['code'] . ($plan['target_language']['exists'] ? '' : ' [missing]')],
                ['Target currency', $plan['target_currency']['code'] . ($plan['target_currency']['exists'] ? '' : ' [missing]')],
                ['Products count', $plan['product_count']],
                ['Orders count', $plan['order_count']],
                ['Uploads count', $plan['upload_count']],
            ]
        );

        $this->line('Business settings preview');
        $this->table(
            ['Type', 'Lang', 'Current Value', 'Target Value'],
            collect($plan['settings'])->map(function (array $setting) {
                return [
                    $setting['type'],
                    $setting['lang'] ?? '[default]',
                    $this->formatValue($setting['current_value']),
                    $this->formatValue($setting['target_value']),
                ];
            })->all()
        );

        $this->line('Remaining legacy branding warnings');
        if (empty($plan['remaining_branding_warnings'])) {
            $this->line('[PASS] No configured legacy branding terms remain in business_settings.');
        } else {
            $this->table(
                ['Term', 'business_settings'],
                collect($plan['remaining_branding_warnings'])->map(fn (array $warning) => [
                    $warning['term'],
                    $warning['business_settings'],
                ])->all()
            );
        }

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

        $this->info('Applying clean baseline business_settings only...');
        $applied = $service->applyPlan($plan);

        $this->table(
            ['Type', 'Lang', 'Status', 'Previous Value', 'Applied Value'],
            collect($applied['settings'])->map(function (array $setting) {
                return [
                    $setting['type'],
                    $setting['lang'] ?? '[default]',
                    $setting['status'],
                    $this->formatValue($setting['previous']),
                    $this->formatValue($setting['value']),
                ];
            })->all()
        );

        $this->info('Apply complete. Only the allowed baseline business_settings were updated.');

        return self::SUCCESS;
    }

    protected function formatValue($value): string
    {
        if ($value === null) {
            return '[missing]';
        }

        if ($value === '') {
            return '[blank]';
        }

        $value = trim(preg_replace('/\s+/', ' ', (string) $value));

        return mb_strlen($value) > 80 ? mb_substr($value, 0, 77) . '...' : $value;
    }
}
