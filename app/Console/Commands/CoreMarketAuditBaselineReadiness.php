<?php

namespace App\Console\Commands;

use App\Services\CoreMarketBaselineReadinessService;
use Illuminate\Console\Command;

class CoreMarketAuditBaselineReadiness extends Command
{
    protected $signature = 'coremarket:audit-baseline-readiness';

    protected $description = 'Run a read-only readiness audit for the CoreMarket clean managed-instance baseline';

    public function handle(CoreMarketBaselineReadinessService $service): int
    {
        $report = $service->buildReport();

        $this->info('CoreMarket baseline readiness audit');
        $this->newLine();

        $this->line('Summary');
        foreach ($report['summary'] as $row) {
            $this->line(sprintf('[%s] %s: %s', $row['status'], $row['label'], $row['message']));
        }

        $this->newLine();
        $this->line('Table counts');
        $this->table(
            ['Status', 'Table', 'Count'],
            collect($report['table_counts'])->map(function (array $row) {
                return [
                    $row['status'],
                    $row['table'],
                    $row['count'] === null ? '[missing]' : $row['count'],
                ];
            })->all()
        );

        $this->line('Required baseline settings');
        $this->table(
            ['Status', 'Setting', 'Value', 'Note'],
            collect($report['required_settings'])->map(function (array $row) {
                return [
                    $row['status'],
                    $row['key'],
                    $row['value'],
                    $row['note'],
                ];
            })->all()
        );

        $this->line('Managed baseline flags');
        $this->table(
            ['Status', 'Setting', 'Value', 'Expectation'],
            collect($report['status_flags'])->map(function (array $row) {
                return [
                    $row['status'],
                    $row['key'],
                    $row['value'],
                    $row['message'],
                ];
            })->all()
        );

        $this->line('Legacy branding warnings');
        if (empty($report['old_branding_warnings'])) {
            $this->line('[PASS] No legacy branding references were detected in audited surfaces.');
        } else {
            $this->table(
                ['Status', 'Term', 'business_settings', 'shops', 'products'],
                collect($report['old_branding_warnings'])->map(function (array $row) {
                    return [
                        $row['status'],
                        $row['term'],
                        $row['business_settings'],
                        $row['shops'],
                        $row['products'],
                    ];
                })->all()
            );
        }

        $this->line('Schema drift');
        $this->line(sprintf(
            '[%s] live_tables=%d tracked_migration_files=%d',
            $report['schema_drift']['status'],
            $report['schema_drift']['live_tables'],
            $report['schema_drift']['tracked_migration_files']
        ));
        $this->line($report['schema_drift']['message']);

        $this->line('Client/demo runtime data');
        $this->table(
            ['Status', 'Table', 'Count'],
            collect($report['baseline_data_counts'])->map(function (array $row) {
                return [
                    $row['status'],
                    $row['table'],
                    $row['count'] === null ? '[missing]' : $row['count'],
                ];
            })->all()
        );

        $this->newLine();
        $this->info('Read-only audit complete. No database changes were made.');

        return self::SUCCESS;
    }
}
