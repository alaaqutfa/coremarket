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

        $this->line('Shop branding preview');
        if (empty($plan['shops'])) {
            $this->line('[INFO] No shop rows were found for baseline-neutral branding updates.');
        } else {
            $this->table(
                ['Shop ID', 'Field', 'Current Value', 'Target Value'],
                collect($plan['shops'])->map(function (array $shop) {
                    return [
                        $shop['id'],
                        $shop['field'],
                        $this->formatValue($shop['current_value']),
                        $this->formatValue($shop['target_value']),
                    ];
                })->all()
            );
        }

        $this->line('Page metadata preview');
        if (empty($plan['pages'])) {
            $this->line('[INFO] No page metadata rows require baseline-neutral cleanup.');
        } else {
            $this->table(
                ['Page ID', 'Field', 'Current Value', 'Target Value'],
                collect($plan['pages'])->map(function (array $page) {
                    return [
                        $page['id'],
                        $page['field'],
                        $this->formatValue($page['current_value']),
                        $this->formatValue($page['target_value']),
                    ];
                })->all()
            );
        }

        $this->line('Page translation preview');
        if (empty($plan['page_translations'])) {
            $this->line('[INFO] No page translation rows require baseline-neutral cleanup.');
        } else {
            $this->table(
                ['Translation ID', 'Lang', 'Field', 'Current Value', 'Target Value'],
                collect($plan['page_translations'])->map(function (array $translation) {
                    return [
                        $translation['id'],
                        $translation['lang'] ?? '[default]',
                        $translation['field'],
                        $this->formatValue($translation['current_value']),
                        $this->formatValue($translation['target_value']),
                    ];
                })->all()
            );
        }

        $this->line('Category metadata preview');
        if (empty($plan['categories'])) {
            $this->line('[INFO] No category metadata rows require baseline-neutral cleanup.');
        } else {
            $this->table(
                ['Category ID', 'Field', 'Current Value', 'Target Value'],
                collect($plan['categories'])->map(function (array $category) {
                    return [
                        $category['id'],
                        $category['field'],
                        $this->formatValue($category['current_value']),
                        $this->formatValue($category['target_value']),
                    ];
                })->all()
            );
        }

        $this->line('Translation preview');
        if (empty($plan['translations'])) {
            $this->line('[INFO] No UI translation rows require baseline-neutral cleanup.');
        } else {
            $this->table(
                ['Translation ID', 'Lang', 'Key', 'Current Value', 'Target Value'],
                collect($plan['translations'])->map(function (array $translation) {
                    return [
                        $translation['id'],
                        $translation['lang'] ?? '[default]',
                        $translation['lang_key'] ?? '[unknown]',
                        $this->formatValue($translation['current_value']),
                        $this->formatValue($translation['target_value']),
                    ];
                })->all()
            );
        }

        $this->line('Message preview');
        if (empty($plan['messages'])) {
            $this->line('[INFO] No message rows require baseline-neutral cleanup.');
        } else {
            $this->table(
                ['Message ID', 'Current Value', 'Target Value'],
                collect($plan['messages'])->map(function (array $message) {
                    return [
                        $message['id'],
                        $this->formatValue($message['current_value']),
                        $this->formatValue($message['target_value']),
                    ];
                })->all()
            );
        }

        $this->line('Role preview');
        if (empty($plan['roles'])) {
            $this->line('[INFO] No role rows require baseline readiness checks.');
        } else {
            $this->table(
                ['Role', 'Guard', 'Exists', 'Role ID'],
                collect($plan['roles'])->map(function (array $role) {
                    return [
                        $role['name'],
                        $role['guard_name'],
                        $role['exists'] ? 'yes' : 'no',
                        $role['current_id'] ?? '[missing]',
                    ];
                })->all()
            );
        }

        $this->line('Client/demo data inventory');
        $this->table(
            ['Status', 'Item', 'Count'],
            collect($plan['inventory'])->map(function (array $row) {
                return [
                    $row['status'],
                    $row['table'],
                    $row['count'] === null ? '[missing]' : $row['count'],
                ];
            })->all()
        );

        $this->line('Remaining legacy branding warnings');
        if (empty($plan['remaining_branding_warnings'])) {
            $this->line('[PASS] No configured legacy branding terms remain in the audited baseline surfaces.');
        } else {
            $this->table(
                ['Term', 'Table', 'Column', 'Count'],
                collect($plan['remaining_branding_warnings'])->map(fn (array $warning) => [
                    $warning['term'],
                    $warning['table'],
                    $warning['column'],
                    $warning['count'],
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

        $this->info('Applying clean baseline business_settings and safe shop branding fields...');
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

        if (! empty($applied['shops'])) {
            $this->line('Shop branding apply result');
            $this->table(
                ['Shop ID', 'Field', 'Status', 'Previous Value', 'Applied Value'],
                collect($applied['shops'])->map(function (array $shop) {
                    return [
                        $shop['id'],
                        $shop['field'],
                        $shop['status'],
                        $this->formatValue($shop['previous']),
                        $this->formatValue($shop['value']),
                    ];
                })->all()
            );
        }

        if (! empty($applied['pages'])) {
            $this->line('Page metadata apply result');
            $this->table(
                ['Page ID', 'Field', 'Status', 'Previous Value', 'Applied Value'],
                collect($applied['pages'])->map(function (array $page) {
                    return [
                        $page['id'],
                        $page['field'],
                        $page['status'],
                        $this->formatValue($page['previous']),
                        $this->formatValue($page['value']),
                    ];
                })->all()
            );
        }

        if (! empty($applied['page_translations'])) {
            $this->line('Page translation apply result');
            $this->table(
                ['Translation ID', 'Lang', 'Field', 'Status', 'Previous Value', 'Applied Value'],
                collect($applied['page_translations'])->map(function (array $translation) {
                    return [
                        $translation['id'],
                        $translation['lang'] ?? '[default]',
                        $translation['field'],
                        $translation['status'],
                        $this->formatValue($translation['previous']),
                        $this->formatValue($translation['value']),
                    ];
                })->all()
            );
        }

        if (! empty($applied['categories'])) {
            $this->line('Category metadata apply result');
            $this->table(
                ['Category ID', 'Field', 'Status', 'Previous Value', 'Applied Value'],
                collect($applied['categories'])->map(function (array $category) {
                    return [
                        $category['id'],
                        $category['field'],
                        $category['status'],
                        $this->formatValue($category['previous']),
                        $this->formatValue($category['value']),
                    ];
                })->all()
            );
        }

        if (! empty($applied['translations'])) {
            $this->line('UI translation apply result');
            $this->table(
                ['Translation ID', 'Lang', 'Key', 'Status', 'Previous Value', 'Applied Value'],
                collect($applied['translations'])->map(function (array $translation) {
                    return [
                        $translation['id'],
                        $translation['lang'] ?? '[default]',
                        $translation['lang_key'] ?? '[unknown]',
                        $translation['status'],
                        $this->formatValue($translation['previous']),
                        $this->formatValue($translation['value']),
                    ];
                })->all()
            );
        }

        if (! empty($applied['messages'])) {
            $this->line('Message apply result');
            $this->table(
                ['Message ID', 'Status', 'Previous Value', 'Applied Value'],
                collect($applied['messages'])->map(function (array $message) {
                    return [
                        $message['id'],
                        $message['status'],
                        $this->formatValue($message['previous']),
                        $this->formatValue($message['value']),
                    ];
                })->all()
            );
        }

        if (! empty($applied['roles'])) {
            $this->line('Role apply result');
            $this->table(
                ['Role', 'Guard', 'Status', 'Role ID'],
                collect($applied['roles'])->map(function (array $role) {
                    return [
                        $role['name'],
                        $role['guard_name'],
                        $role['status'],
                        $role['role_id'] ?? '[missing]',
                    ];
                })->all()
            );
        }

        $this->info('Apply complete. Only the allowed baseline business_settings and safe public metadata fields were updated.');

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
