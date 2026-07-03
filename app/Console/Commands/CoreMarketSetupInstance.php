<?php

namespace App\Console\Commands;

use App\Services\CoreMarketInstanceSetupService;
use Illuminate\Console\Command;

class CoreMarketSetupInstance extends Command
{
    protected $signature = 'coremarket:setup-instance
                            {instance_id : Generic instance identifier such as client-store}
                            {--dry-run : Preview the managed instance setup plan without writing data}
                            {--apply : Apply the allowed business settings only after explicit confirmation}
                            {--confirm-instance-setup : Confirm that this managed instance setup should write the safe business settings}
                            {--create-store-admin : Preview a Store Admin creation action after apply validation}
                            {--store-name= : Public store name}
                            {--domain= : Public domain without secrets}
                            {--plan=ecommerce_starter : CoreMarket plan code}
                            {--admin-name= : Store admin display name}
                            {--admin-email= : Store admin email address}
                            {--site-motto= : Public site motto}
                            {--meta-title= : Meta title}
                            {--meta-description= : Meta description}
                            {--whatsapp= : WhatsApp or support phone number}
                            {--contact-phone= : Public contact phone}
                            {--contact-email= : Public contact email}
                            {--footer-text= : Footer copyright text}
                            {--country= : Default country label}
                            {--city= : Default city label}
                            {--timezone=UTC : Instance timezone preview}';

    protected $description = 'Build a generic managed instance setup plan for CoreMarket clients';

    public function handle(CoreMarketInstanceSetupService $setupService): int
    {
        $instanceId = (string) $this->argument('instance_id');
        $applyRequested = (bool) $this->option('apply');
        $dryRun = ! $applyRequested;

        $plan = $setupService->buildPlan($instanceId, [
            'apply' => $applyRequested,
            'dry_run' => $dryRun,
            'confirm_instance_setup' => (bool) $this->option('confirm-instance-setup'),
            'create_store_admin' => (bool) $this->option('create-store-admin'),
            'store_name' => $this->option('store-name'),
            'domain' => $this->option('domain'),
            'plan' => $this->option('plan'),
            'admin_name' => $this->option('admin-name'),
            'admin_email' => $this->option('admin-email'),
            'site_motto' => $this->option('site-motto'),
            'meta_title' => $this->option('meta-title'),
            'meta_description' => $this->option('meta-description'),
            'whatsapp' => $this->option('whatsapp'),
            'contact_phone' => $this->option('contact-phone'),
            'contact_email' => $this->option('contact-email'),
            'footer_text' => $this->option('footer-text'),
            'country' => $this->option('country'),
            'city' => $this->option('city'),
            'timezone' => $this->option('timezone'),
        ]);

        $validationErrors = $setupService->validateApplyRequirements($plan);

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
                ['Mode', $plan['apply_requested'] ? 'apply' : 'dry-run'],
                ['Confirmed', $plan['confirmed'] ? 'yes' : 'no'],
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
                ['Create requested', $plan['store_admin']['create_requested'] ? 'yes' : 'no'],
                ['User type', $plan['store_admin']['user_type']],
                ['Role', $plan['store_admin']['role']],
                ['Name', $plan['store_admin']['name'] ?? '[not provided]'],
                ['Email', $plan['store_admin']['email'] ?? '[not provided]'],
                ['Status', $plan['store_admin']['status']],
            ]
        );

        $this->line('Business settings keys');
        foreach (array_keys($plan['business_settings']) as $businessSettingKey) {
            $this->line('- ' . $businessSettingKey);
        }

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

        $this->line('.env actions');
        $this->line('- Manual only. This command does not modify .env files.');
        $this->line('Product import');
        $this->line('- Not handled by this command.');

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

        $this->info('Applying allowed business_settings only...');
        $applied = $setupService->applyBusinessSettings($plan['business_settings']);

        $this->table(
            ['Key', 'Status', 'Previous Value', 'Applied Value'],
            collect($applied)->map(function (array $row) {
                return [
                    $row['key'],
                    $row['status'],
                    $row['previous'] ?? '[not set]',
                    $row['value'] ?? '[set later]',
                ];
            })->all()
        );

        if ($plan['store_admin']['create_requested']) {
            $this->warn('Store Admin creation remains preview-only in this step. No user was created.');
        }

        $this->info('Apply complete. Only the allowed business_settings were updated.');

        return self::SUCCESS;
    }
}
