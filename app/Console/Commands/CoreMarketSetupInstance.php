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
                            {--create-store-admin : Create or update the Store Admin during apply mode}
                            {--store-name= : Public store name}
                            {--domain= : Public domain without secrets}
                            {--plan=starter : CoreMarket applied plan code}
                            {--store-mode=single_store : Store mode single_store, marketplace, or owned_coremarket_store}
                            {--admin-name= : Store admin display name}
                            {--admin-email= : Store admin email address}
                            {--store-admin-password= : Store admin password for explicit creation or update}
                            {--support-email= : Owner or support contact email shown on the storefront}
                            {--site-motto= : Public site motto}
                            {--meta-title= : Meta title}
                            {--meta-description= : Meta description}
                            {--whatsapp= : WhatsApp or support phone number}
                            {--contact-phone= : Public contact phone}
                            {--contact-email= : Public contact email}
                            {--contact-address= : Public contact address}
                            {--currency=USD : Default currency code}
                            {--language=en : Default language code}
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
            'store_mode' => $this->option('store-mode'),
            'admin_name' => $this->option('admin-name'),
            'admin_email' => $this->option('admin-email'),
            'store_admin_password' => $this->option('store-admin-password'),
            'support_email' => $this->option('support-email'),
            'site_motto' => $this->option('site-motto'),
            'meta_title' => $this->option('meta-title'),
            'meta_description' => $this->option('meta-description'),
            'whatsapp' => $this->option('whatsapp'),
            'contact_phone' => $this->option('contact-phone'),
            'contact_email' => $this->option('contact-email'),
            'contact_address' => $this->option('contact-address'),
            'currency' => $this->option('currency'),
            'language' => $this->option('language'),
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
                ['Store Mode', $plan['store_mode']],
                ['Store Name', $plan['store_name'] ?? '[not provided]'],
                ['Domain', $plan['domain'] ?? '[not provided]'],
                ['Currency', $plan['currency']['code'] . ($plan['currency']['exists'] ? '' : ' [missing]')],
                ['Language', $plan['language']['code'] . ($plan['language']['exists'] ? '' : ' [missing]')],
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

        $this->line('Shop branding preview');
        if (empty($plan['shops'])) {
            $this->line('[INFO] No existing shop rows were found to align with the instance branding.');
        } else {
            $this->table(
                ['Shop ID', 'Field', 'Current Value', 'Planned Value'],
                collect($plan['shops'])->map(fn (array $row) => [
                    $row['id'],
                    $row['field'],
                    $row['current_value'] ?? '[blank]',
                    $row['target_value'] ?? '[blank]',
                ])->all()
            );
        }

        $this->line('Runtime access preview');
        $this->table(
            ['Runtime Key', 'Resolved Value'],
            collect($plan['runtime_access']['features'])->map(fn ($value, $key) => [$key, $value ? 'enabled' : 'disabled'])
                ->merge(
                    collect($plan['runtime_access']['limits'])->map(fn ($value, $key) => [$key, $value === null ? 'unlimited' : $value])
                )->values()->all()
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
                ['Action', $plan['store_admin']['action']],
                ['Password supplied', $plan['store_admin']['password_supplied'] ? 'yes' : 'no'],
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

        $this->line('Notes');
        foreach ($plan['notes'] as $item) {
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

        $this->info('Applying allowed business_settings and optional Store Admin changes...');
        $applied = $setupService->applyPlan($plan);

        $this->table(
            ['Key', 'Status', 'Previous Value', 'Applied Value'],
            collect($applied['business_settings'])->map(function (array $row) {
                return [
                    $row['key'],
                    $row['status'],
                    $row['previous'] ?? '[not set]',
                    $row['value'] ?? '[set later]',
                ];
            })->all()
        );

        if (! empty($applied['shops'])) {
            $this->line('Shop branding apply result');
            $this->table(
                ['Shop ID', 'Field', 'Status', 'Previous Value', 'Applied Value'],
                collect($applied['shops'])->map(function (array $row) {
                    return [
                        $row['id'],
                        $row['field'],
                        $row['status'],
                        $row['previous'] ?? '[blank]',
                        $row['value'] ?? '[blank]',
                    ];
                })->all()
            );
        }

        if ($applied['store_admin']) {
            $this->line('Store Admin apply result');
            $this->table(
                ['Field', 'Value'],
                [
                    ['Email', $applied['store_admin']['email']],
                    ['Status', $applied['store_admin']['status']],
                ]
            );
        }

        $this->info('Apply complete. Only the allowed business_settings, safe shop branding fields, and explicit Store Admin changes were updated.');

        return self::SUCCESS;
    }
}
