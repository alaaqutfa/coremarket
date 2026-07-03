<?php

namespace App\Console\Commands;

use App\Services\CoreMarketInstanceSetupService;
use Illuminate\Console\Command;

class CoreMarketSetupInstance extends Command
{
    protected $signature = 'coremarket:setup-instance
                            {instance_id : Generic instance identifier such as client-store}
                            {--dry-run : Preview the managed instance setup plan without writing data}
                            {--apply : Apply mode is reserved for a later step and is currently blocked}
                            {--store-name= : Public store name}
                            {--domain= : Public domain without secrets}
                            {--plan=ecommerce_starter : CoreMarket plan code}
                            {--admin-name= : Store admin display name}
                            {--admin-email= : Store admin email address}
                            {--whatsapp= : WhatsApp or support phone number}
                            {--currency= : Default currency code or id}
                            {--country= : Default country label}
                            {--city= : Default city label}
                            {--timezone=UTC : Instance timezone preview}';

    protected $description = 'Build a generic managed instance setup plan for CoreMarket clients';

    public function handle(CoreMarketInstanceSetupService $setupService): int
    {
        $instanceId = (string) $this->argument('instance_id');
        $applyRequested = (bool) $this->option('apply');
        $dryRun = $applyRequested ? true : (bool) $this->option('dry-run');

        if ($applyRequested) {
            $this->warn('Apply mode is not enabled yet. This command is running in dry-run mode only.');
        }

        $plan = $setupService->buildPlan($instanceId, [
            'apply' => $applyRequested,
            'dry_run' => $dryRun,
            'store_name' => $this->option('store-name'),
            'domain' => $this->option('domain'),
            'plan' => $this->option('plan'),
            'admin_name' => $this->option('admin-name'),
            'admin_email' => $this->option('admin-email'),
            'whatsapp' => $this->option('whatsapp'),
            'currency' => $this->option('currency'),
            'country' => $this->option('country'),
            'city' => $this->option('city'),
            'timezone' => $this->option('timezone'),
        ]);

        $this->info('CoreMarket managed instance setup plan');
        $this->newLine();

        $this->table(
            ['Field', 'Value'],
            [
                ['Instance ID', $plan['instance_id']],
                ['Plan', $plan['plan_code']],
                ['Store Name', $plan['store_name'] ?? '[not provided]'],
                ['Domain', $plan['domain'] ?? '[not provided]'],
                ['Country', $plan['country'] ?? '[not provided]'],
                ['City', $plan['city'] ?? '[not provided]'],
                ['Mode', $plan['dry_run'] ? 'dry-run' : 'apply'],
            ]
        );

        $this->line('Environment preview');
        $this->table(
            ['Key', 'Preview'],
            collect($plan['env'])->map(fn ($value, $key) => [$key, $value ?? '[set later]'])->all()
        );

        $this->line('Business settings preview');
        $this->table(
            ['Key', 'Planned Value'],
            collect($plan['business_settings'])->map(fn ($value, $key) => [$key, $value ?? '[set later]'])->all()
        );

        $this->line('Store Admin preview');
        $this->table(
            ['Field', 'Value'],
            [
                ['Create later', $plan['store_admin']['create_user_later'] ? 'yes' : 'no'],
                ['User type', $plan['store_admin']['user_type']],
                ['Role', $plan['store_admin']['role']],
                ['Name', $plan['store_admin']['name'] ?? '[not provided]'],
                ['Email', $plan['store_admin']['email'] ?? '[not provided]'],
                ['Password', $plan['store_admin']['password_strategy']],
            ]
        );

        $this->line('Media requirements');
        foreach ($plan['media']['required'] as $mediaKey) {
            $this->line('- ' . $mediaKey);
        }
        foreach ($plan['media']['notes'] as $note) {
            $this->line('- ' . $note);
        }

        $this->line('Checklist');
        foreach ($plan['checklist'] as $item) {
            $this->line('- ' . $item);
        }

        return self::SUCCESS;
    }
}
