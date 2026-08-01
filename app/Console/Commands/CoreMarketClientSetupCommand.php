<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\CoreMarketRuntimeDatabaseResolver;
use Database\Seeders\OperationsPermissionSeeder;
use Database\Seeders\StaffRolePresetSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Throwable;

class CoreMarketClientSetupCommand extends Command
{
    protected $signature = 'coremarket:client-setup
                            {--project= : Client project or store name}
                            {--support-admin-email= : CorePilot technical support administrator email}
                            {--support-admin-password= : CorePilot technical support administrator password}
                            {--client-admin-email= : Client-owned store administrator email}
                            {--client-admin-password= : Client-owned store administrator password}
                            {--admin-email= : Deprecated alias for --support-admin-email}
                            {--email= : Deprecated alias for --support-admin-email}
                            {--password= : Deprecated alias for --support-admin-password}
                            {--pass= : Deprecated alias for --support-admin-password}
                            {--create-support-admin : Explicitly create or update the support administrator}
                            {--create-client-admin : Explicitly create or update the client administrator}
                            {--skip-support-admin : Do not create or update the support administrator}
                            {--skip-client-admin : Do not create or update the client administrator}
                            {--client-admin-full-access : Promote the client administrator to full system Admin; internal owned stores only}
                            {--repair-admin-access : Reapply the audited support/client access model to existing accounts}
                            {--domain= : Client domain without secrets}
                            {--plan=enterprise : Applied plan label}
                            {--write-env : Back up and update receiver configuration in .env}
                            {--production-env : With --write-env, set APP_ENV=production, APP_DEBUG=false, and APP_URL}
                            {--enable-enterprise : Enable the approved Enterprise feature settings}
                            {--force : Allow password replacement, token rotation, or conversion of an existing non-admin user}';

    protected $description = 'Safely prepare client permissions, separate support/client admins, receiver configuration, and features';

    private array $warnings = [];

    public function handle(): int
    {
        $input = $this->validatedInput();
        if ($input === null) {
            return self::FAILURE;
        }

        $preflight = $this->preflight($input);
        $this->printPreflight($preflight);
        foreach ($preflight['warnings'] as $warning) {
            $this->warn($warning);
        }
        if (! $preflight['ready']) {
            foreach ($preflight['errors'] as $error) {
                $this->error($error);
            }
            $this->warn('No database or environment changes were made.');

            return self::FAILURE;
        }

        try {
            $result = DB::transaction(function () use ($input, $preflight) {
                $seeders = 'skipped; role system unavailable';
                if ($preflight['role_system_ready']) {
                    $this->runSeeder(OperationsPermissionSeeder::class);
                    $this->runSeeder(StaffRolePresetSeeder::class);
                    $seeders = 'OperationsPermissionSeeder, StaffRolePresetSeeder';
                } else {
                    $this->warnings[] = 'roles/permissions tables are unavailable; permission seeders and role assignment were skipped.';
                }

                $supportAdmin = $input['create_support_admin']
                    ? $this->ensureAdmin(
                        $input['support_admin_email'],
                        $input['support_admin_password'],
                        $input['project'].' CorePilot Support',
                        'support',
                        true,
                        $input['force'],
                        $preflight['role_system_ready']
                    )
                    : $this->skippedAdmin();
                $clientAdmin = $input['create_client_admin']
                    ? $this->ensureAdmin(
                        $input['client_admin_email'],
                        $input['client_admin_password'],
                        $input['project'].' Owner',
                        'client',
                        $input['client_admin_full_access'],
                        $input['force'],
                        $preflight['role_system_ready']
                    )
                    : $this->skippedAdmin();
                $settings = $input['enable_enterprise']
                    ? $this->enableEnterpriseSettings($input)
                    : ['enabled' => false, 'updated' => 0];

                return compact('supportAdmin', 'clientAdmin', 'settings', 'seeders');
            });
        } catch (Throwable $exception) {
            $this->error('Client setup failed before environment update: '.$exception->getMessage());
            $this->warn('Database changes from this command were rolled back.');

            return self::FAILURE;
        }

        try {
            $environment = $this->prepareEnvironment($input);
        } catch (Throwable $exception) {
            $this->error('Client setup database changes completed, but .env handling failed: '.$exception->getMessage());
            $this->warn('Restore the reported .env backup if a partial filesystem write is suspected.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('CoreMarket client setup completed.');
        $this->table(['Account', 'Email', 'Status', 'Access type', 'Assigned role'], [
            ['CorePilot Support Admin', $input['support_admin_email'] ?: '[skipped]', $result['supportAdmin']['status'], $result['supportAdmin']['access_type'], $result['supportAdmin']['role'] ?? '[none]'],
            ['Client Owner/Admin', $input['client_admin_email'] ?: '[skipped]', $result['clientAdmin']['status'], $result['clientAdmin']['access_type'], $result['clientAdmin']['role'] ?? '[none]'],
        ]);
        $this->table(['Result', 'Value'], [
            ['Seeders', $result['seeders']],
            ['Enterprise enabled', $result['settings']['enabled'] ? 'yes' : 'no'],
            ['Enterprise settings updated', $result['settings']['updated']],
            ['Admin access repair', $input['repair_admin_access'] ? 'requested and applied' : 'access model enforced'],
            ['Canonical token key', 'COREPILOT_RUNTIME_SYNC_TOKEN'],
            ['Sync token status', $environment['token_status']],
            ['Sync token preview', $environment['token_preview']],
            ['Runtime DB connection', $environment['runtime_connection']],
            ['Runtime business_settings', $environment['runtime_business_settings'] ? 'present' : 'missing'],
            ['.env changed', $environment['written'] ? 'yes' : 'no'],
            ['.env backup', $environment['backup'] ?? '[not required]'],
        ]);

        foreach ($this->warnings as $warning) {
            $this->warn($warning);
        }
        if (! $input['write_env']) {
            $this->warn('Receiver values were not persisted. Re-run with --write-env after reviewing the target .env.');
        }
        if ($input['client_admin_full_access']) {
            $this->warn('Client Admin full access is enabled. Use this only for an internal CorePilot-owned store.');
        }

        $baseUrl = 'https://'.trim($input['domain'], '/');
        $this->line('Next CorePilotOS values');
        $this->line('- API Base URL: '.$baseUrl.'/');
        $this->line('- Store URL: '.$baseUrl.'/');
        $this->line('- Admin URL: '.$baseUrl.'/admin');
        $this->line('- POS URL: '.$baseUrl.'/operations/pos');
        $this->line('- Token env key: COREPILOT_RUNTIME_SYNC_TOKEN');
        $this->line('- Header: X-CorePilot-Sync-Token');
        $this->line('- Runtime DB connection: '.$environment['runtime_connection']);
        $this->line('Next steps');
        $this->line('1. php artisan optimize:clear');
        $this->line('2. php artisan storage:link');
        $this->line('3. php artisan coremarket:receiver-diagnostics');
        $this->line('4. php artisan coremarket:branch-inventory-initialize --dry-run');
        $this->line('5. In CorePilotOS, paste the full COREPILOT_RUNTIME_SYNC_TOKEN into "Replace API token".');
        $this->line('6. Login: '.$baseUrl.'/login');

        return self::SUCCESS;
    }

    private function validatedInput(): ?array
    {
        $legacyEmail = trim((string) ($this->option('admin-email') ?: $this->option('email')));
        $legacyPassword = (string) ($this->option('password') ?: $this->option('pass'));
        $supportEmail = trim((string) ($this->option('support-admin-email') ?: $legacyEmail));
        $supportPassword = (string) ($this->option('support-admin-password') ?: $legacyPassword);
        $clientEmail = trim((string) $this->option('client-admin-email'));
        $clientPassword = (string) $this->option('client-admin-password');
        $project = trim((string) $this->option('project'));
        $domain = trim((string) $this->option('domain'));
        $plan = strtolower(trim((string) $this->option('plan')));
        $errors = [];

        if ($legacyEmail !== '') {
            $this->warnings[] = 'Deprecated: --admin-email is treated as support admin. Prefer --support-admin-email or --client-admin-email.';
        }
        if (
            $legacyEmail !== ''
            && $this->option('support-admin-email')
            && strcasecmp($legacyEmail, (string) $this->option('support-admin-email')) !== 0
        ) {
            $errors[] = 'Legacy Admin email and --support-admin-email must match when both are supplied.';
        }
        if (
            $legacyPassword !== ''
            && $this->option('support-admin-password')
            && $legacyPassword !== (string) $this->option('support-admin-password')
        ) {
            $errors[] = 'Legacy Admin password and --support-admin-password must match when both are supplied.';
        }
        if ($this->option('create-support-admin') && $this->option('skip-support-admin')) {
            $errors[] = '--create-support-admin and --skip-support-admin cannot be combined.';
        }
        if ($this->option('create-client-admin') && $this->option('skip-client-admin')) {
            $errors[] = '--create-client-admin and --skip-client-admin cannot be combined.';
        }

        $createSupport = ! $this->option('skip-support-admin') && $supportEmail !== '';
        $createClient = ! $this->option('skip-client-admin') && $clientEmail !== '';
        if ($this->option('client-admin-full-access') && ! $createClient) {
            $errors[] = '--client-admin-full-access requires an active --client-admin-email.';
        }
        if ($this->option('create-support-admin') && $supportEmail === '') {
            $errors[] = '--create-support-admin requires --support-admin-email.';
        }
        if ($this->option('create-client-admin') && $clientEmail === '') {
            $errors[] = '--create-client-admin requires --client-admin-email.';
        }
        if (! $createSupport && ! $createClient) {
            $errors[] = 'Provide a support or client Admin email. No ambiguous or empty Admin setup is allowed.';
        }
        if ($createSupport && ! filter_var($supportEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid support Admin email is required.';
        }
        if ($createClient && ! filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid client Admin email is required.';
        }
        if ($createSupport && $createClient && strcasecmp($supportEmail, $clientEmail) === 0) {
            $errors[] = 'Support Admin and Client Admin must use different email addresses.';
        }
        foreach ([$supportPassword, $clientPassword] as $password) {
            if ($password !== '' && strlen($password) < 8) {
                $errors[] = 'Admin passwords must contain at least 8 characters.';
                break;
            }
        }
        if ($project === '') {
            $errors[] = '--project is required.';
        }
        if ($domain === '') {
            $errors[] = '--domain is required.';
        }
        if (! in_array($plan, ['starter', 'business', 'marketplace', 'enterprise'], true)) {
            $errors[] = '--plan must be starter, business, marketplace, or enterprise.';
        }
        if ($this->option('enable-enterprise') && $plan !== 'enterprise') {
            $errors[] = '--enable-enterprise requires --plan=enterprise.';
        }
        if ($this->option('production-env') && ! $this->option('write-env')) {
            $errors[] = '--production-env requires --write-env.';
        }

        foreach ($errors as $error) {
            $this->error($error);
        }

        return $errors === [] ? [
            'project' => $project,
            'support_admin_email' => strtolower($supportEmail),
            'support_admin_password' => $supportPassword,
            'client_admin_email' => strtolower($clientEmail),
            'client_admin_password' => $clientPassword,
            'create_support_admin' => $createSupport,
            'create_client_admin' => $createClient,
            'client_admin_full_access' => (bool) $this->option('client-admin-full-access'),
            'repair_admin_access' => (bool) $this->option('repair-admin-access'),
            'domain' => preg_replace('#^https?://#i', '', rtrim($domain, '/')),
            'plan' => $plan,
            'write_env' => (bool) $this->option('write-env'),
            'production_env' => (bool) $this->option('production-env'),
            'enable_enterprise' => (bool) $this->option('enable-enterprise'),
            'force' => (bool) $this->option('force'),
        ] : null;
    }

    private function preflight(array $input): array
    {
        $errors = [];
        $warnings = [];
        $defaultConnection = (string) config('database.default', 'mysql');
        $configuredDatabase = config("database.connections.{$defaultConnection}.database");
        $actualDatabase = null;
        $selectedDatabase = null;

        try {
            $actualDatabase = DB::connection($defaultConnection)->getDatabaseName();
            $selectedDatabase = DB::connection($defaultConnection)->selectOne('SELECT DATABASE() AS database_name')->database_name ?? null;
        } catch (Throwable $exception) {
            $errors[] = 'Database connection is unavailable: '.$exception->getMessage();
        }

        $connectionReady = filled($actualDatabase) && filled($selectedDatabase);
        $usersTable = $connectionReady && $this->tableExists('users');
        $settingsTable = $connectionReady && $this->tableExists('business_settings');
        $rolesTable = $connectionReady && $this->tableExists('roles');
        $permissionsTable = $connectionReady && $this->tableExists('permissions');
        if ($connectionReady && $actualDatabase !== $selectedDatabase) {
            $errors[] = 'Configured connection and selected MySQL database do not match.';
        }
        if ($connectionReady && filled($configuredDatabase) && $configuredDatabase !== $actualDatabase) {
            $errors[] = 'Configured DB_DATABASE and actual connection database do not match.';
        }
        if (! $usersTable) {
            $errors[] = 'Required users table is missing.';
        }
        if (! $settingsTable) {
            $errors[] = 'Required business_settings table is missing.';
        }

        $runtime = app(CoreMarketRuntimeDatabaseResolver::class)->resolve();
        if (! ($runtime['has_business_settings_table'] ?? false)) {
            $warnings[] = 'Runtime snapshot storage is unavailable. Set COREMARKET_RUNTIME_DB_CONNECTION=mysql for single-database installs.';
        }
        if (app()->environment('local') && ! $this->isLocalDomain($input['domain'])) {
            $warnings[] = 'APP_ENV is local. For production domain use APP_ENV=production.';
        }
        if (str_contains(strtolower((string) config('app.url')), 'localhost')) {
            $warnings[] = 'APP_URL still points to localhost. Set APP_URL=https://your-domain.';
        }

        return [
            'ready' => $errors === [] && $connectionReady,
            'app_env' => app()->environment(),
            'app_url' => config('app.url'),
            'default_connection' => $defaultConnection,
            'configured_database' => $configuredDatabase,
            'actual_database' => $actualDatabase,
            'selected_database' => $selectedDatabase,
            'users_count' => $usersTable ? DB::table('users')->count() : null,
            'business_settings' => $settingsTable,
            'roles' => $rolesTable,
            'permissions' => $permissionsTable,
            'role_system_ready' => $rolesTable && $permissionsTable,
            'runtime' => $runtime,
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }

    private function printPreflight(array $preflight): void
    {
        $runtime = $preflight['runtime'];
        $this->info('CoreMarket client setup preflight');
        $this->table(['Check', 'Value'], [
            ['APP_ENV', $preflight['app_env']],
            ['APP_URL', $preflight['app_url']],
            ['Default DB connection', $preflight['default_connection']],
            ['Default DB database', $preflight['actual_database'] ?: '[missing]'],
            ['Configured DB_DATABASE', $preflight['configured_database'] ?: '[missing]'],
            ['Selected MySQL database', $preflight['selected_database'] ?: '[missing]'],
            ['Runtime snapshot connection', $runtime['runtime_connection_name'] ?? '[missing]'],
            ['Runtime snapshot database', $runtime['runtime_database_name'] ?? '[missing]'],
            ['Runtime business_settings', ($runtime['has_business_settings_table'] ?? false) ? 'present' : 'missing'],
            ['Users before', $preflight['users_count'] ?? '[table missing]'],
            ['business_settings table', $preflight['business_settings'] ? 'present' : 'missing'],
            ['roles table', $preflight['roles'] ? 'present' : 'missing'],
            ['permissions table', $preflight['permissions'] ? 'present' : 'missing'],
        ]);
    }

    private function runSeeder(string $seeder): void
    {
        $exitCode = $this->callSilent('db:seed', ['--class' => $seeder, '--force' => true]);
        if ($exitCode !== self::SUCCESS) {
            throw new \RuntimeException(class_basename($seeder).' failed with exit code '.$exitCode.'.');
        }
    }

    private function tableExists(string $table): bool
    {
        $overrides = config('coremarket.client_setup.required_table_overrides', []);
        if (array_key_exists($table, $overrides)) {
            return (bool) $overrides[$table];
        }

        return Schema::hasTable($table);
    }

    private function ensureAdmin(
        string $email,
        string $password,
        string $displayName,
        string $accountType,
        bool $fullAccess,
        bool $force,
        bool $roleSystemReady
    ): array {
        $existing = DB::table('users')->where('email', $email)->first();
        if ($existing && isset($existing->user_type) && ! in_array($existing->user_type, ['admin', 'staff'], true) && ! $force) {
            throw new \RuntimeException(ucfirst($accountType).' Admin email belongs to a non-admin user. Use --force only after reviewing that account.');
        }
        if (! $existing && $password === '') {
            throw new \RuntimeException('A password is required when creating the '.ucfirst($accountType).' Admin.');
        }

        $role = $roleSystemReady ? $this->resolveAdminRole($accountType, $fullAccess) : null;
        $targetUserType = $fullAccess ? 'admin' : 'staff';
        $columns = array_flip(Schema::getColumnListing('users'));
        $now = now();

        if (! $existing) {
            $values = [];
            $this->putIfColumn($values, $columns, 'name', $displayName);
            $this->putIfColumn($values, $columns, 'f_name', $displayName);
            $this->putIfColumn($values, $columns, 'l_name', 'Administrator');
            $this->putIfColumn($values, $columns, 'email', $email);
            $this->putIfColumn($values, $columns, 'phone', '0000000000');
            $this->putIfColumn($values, $columns, 'password', Hash::make($password));
            $this->putIfColumn($values, $columns, 'user_type', $targetUserType);
            $this->putIfColumn($values, $columns, 'status', 1);
            $this->putIfColumn($values, $columns, 'is_active', 1);
            $this->putIfColumn($values, $columns, 'banned', 0);
            $this->putIfColumn($values, $columns, 'email_verified_at', $now);
            $this->putIfColumn($values, $columns, 'created_at', $now);
            $this->putIfColumn($values, $columns, 'updated_at', $now);
            $this->putRoleColumn($values, $columns, $role);
            $userId = DB::table('users')->insertGetId($values);
            $status = 'created';
        } else {
            $userId = $existing->id;
            $updates = [];
            $this->putIfColumn($updates, $columns, 'user_type', $targetUserType);
            $this->putIfColumn($updates, $columns, 'status', 1);
            $this->putIfColumn($updates, $columns, 'is_active', 1);
            $this->putIfColumn($updates, $columns, 'banned', 0);
            $this->putIfColumn($updates, $columns, 'email_verified_at', $existing->email_verified_at ?? $now);
            $this->putRoleColumn($updates, $columns, $role);
            if ($force) {
                if ($password !== '') {
                    $this->putIfColumn($updates, $columns, 'password', Hash::make($password));
                }
            }
            if ($updates !== []) {
                $this->putIfColumn($updates, $columns, 'updated_at', $now);
                DB::table('users')->where('id', $userId)->update($updates);
            }
            $status = $force ? 'existing account updated' : 'existing access repaired; password unchanged';
        }

        if ($role && Schema::hasTable('model_has_roles')) {
            User::query()->findOrFail($userId)->syncRoles([$role]);
            $staffExists = Schema::hasTable('staff') && DB::table('staff')->where('user_id', $userId)->exists();
            if (Schema::hasTable('staff') && (! $fullAccess || $staffExists)) {
                $staffColumns = array_flip(Schema::getColumnListing('staff'));
                $staffValues = ['role_id' => $role->id];
                $this->putIfColumn($staffValues, $staffColumns, 'updated_at', $now);
                if (! $staffExists) {
                    $this->putIfColumn($staffValues, $staffColumns, 'created_at', $now);
                }
                DB::table('staff')->updateOrInsert(['user_id' => $userId], $staffValues);
            }
        } else {
            $this->warnings[] = ucfirst($accountType).' Admin was saved, but no suitable role could be assigned.';
        }

        $accessType = $fullAccess
            ? ($accountType === 'support' ? 'system_admin/full_admin' : 'full_admin')
            : 'store_admin/client_admin';

        return ['id' => $userId, 'status' => $status, 'role' => $role?->name, 'access_type' => $accessType];
    }

    private function skippedAdmin(): array
    {
        return ['id' => null, 'status' => 'skipped', 'role' => null, 'access_type' => 'skipped'];
    }

    private function resolveAdminRole(string $accountType, bool $fullAccess): ?Role
    {
        if (! Schema::hasTable('roles')) {
            return null;
        }

        if ($fullAccess) {
            return Role::query()->firstOrCreate([
                'name' => 'Super Admin',
                'guard_name' => 'web',
            ]);
        }

        $preferred = $accountType === 'client'
            ? ['store_admin', 'owner_general_manager']
            : ['owner_general_manager', 'store_admin'];
        foreach ($preferred as $roleName) {
            $role = Role::query()->where('guard_name', 'web')->where('name', $roleName)->first();
            if ($role) {
                return $role;
            }
        }

        return null;
    }

    private function enableEnterpriseSettings(array $input): array
    {
        $settings = [
            'coremarket.plan' => $input['plan'],
            'coremarket.plan_name' => 'Enterprise',
            'coremarket.status' => 'active',
            'coremarket.project_name' => $input['project'],
            'coremarket.domain' => $input['domain'],
            'inventory.strict_inventory_mode' => '1',
            'inventory.allow_negative_stock' => '0',
            'inventory.branch_inventory_enabled' => '1',
            'inventory.serial_tracking_enabled' => '1',
            'inventory.imei_tracking_enabled' => '1',
            'inventory.warranty_tracking_enabled' => '1',
            'catalog.advanced_variants_enabled' => '1',
            'pricing.branch_pricing_enabled' => '1',
            'pricing.branch_pricing_priority' => 'branch_price_first',
            'customer_accounts.enabled' => '1',
            'customer_accounts.credit_limits_enabled' => '1',
            'customer_accounts.payment_terms_enabled' => '1',
            'customer_accounts.pay_on_account_enabled' => '1',
            'pos.pay_on_account_enabled' => '1',
            'checkout.pay_on_account_enabled' => '1',
        ];
        $columns = array_flip(Schema::getColumnListing('business_settings'));
        $keyColumn = isset($columns['key']) ? 'key' : (isset($columns['type']) ? 'type' : null);
        if (! $keyColumn || ! isset($columns['value'])) {
            throw new \RuntimeException('business_settings must contain key/type and value columns.');
        }

        foreach ($settings as $key => $value) {
            $identity = [$keyColumn => $key];
            if (isset($columns['lang'])) {
                $identity['lang'] = null;
            }
            $values = ['value' => $value];
            $this->putIfColumn($values, $columns, 'updated_at', now());
            if (! DB::table('business_settings')->where($identity)->exists()) {
                $this->putIfColumn($values, $columns, 'created_at', now());
            }
            DB::table('business_settings')->updateOrInsert($identity, $values);
        }
        Cache::forget('business_settings');

        return ['enabled' => true, 'updated' => count($settings)];
    }

    private function prepareEnvironment(array $input): array
    {
        $generated = bin2hex(random_bytes(32));
        if (! $input['write_env']) {
            return [
                'token_status' => 'generated preview only; not persisted',
                'token_preview' => $this->maskToken($generated),
                'runtime_connection' => (string) config('coremarket.runtime_snapshot.connection', 'coremarket_runtime'),
                'runtime_business_settings' => (bool) (app(CoreMarketRuntimeDatabaseResolver::class)->resolve()['has_business_settings_table'] ?? false),
                'written' => false,
                'backup' => null,
            ];
        }

        $envPath = (string) config('coremarket.client_setup.env_path', app()->environmentFilePath());
        if (! File::isFile($envPath)) {
            throw new \RuntimeException('.env file was not found at the configured environment path.');
        }
        $contents = File::get($envPath);
        $canonical = $this->envValue($contents, 'COREPILOT_RUNTIME_SYNC_TOKEN');
        $legacy = $this->envValue($contents, 'COREPILOT_SYNC_TOKEN');

        if ($input['force']) {
            $token = $generated;
            $tokenStatus = ($canonical || $legacy) ? 'rotated canonical and legacy tokens' : 'created canonical and legacy tokens';
        } elseif ($canonical) {
            $token = $canonical;
            $tokenStatus = 'kept canonical token';
            if ($legacy && ! hash_equals($canonical, $legacy)) {
                $this->warnings[] = 'COREPILOT_RUNTIME_SYNC_TOKEN and legacy COREPILOT_SYNC_TOKEN differ. Canonical token was kept; use --force only for an intentional synchronized rotation.';
            }
        } elseif ($legacy) {
            $token = $legacy;
            $tokenStatus = 'copied legacy token to canonical key';
        } else {
            $token = $generated;
            $tokenStatus = 'created canonical and legacy tokens';
        }

        $updates = ['COREPILOT_RUNTIME_SYNC_TOKEN' => $token];
        if ($input['force'] || ! $legacy || ($canonical === null && $legacy !== null)) {
            $updates['COREPILOT_SYNC_TOKEN'] = $token;
        }

        $configuredRuntime = $this->envValue($contents, 'COREMARKET_RUNTIME_DB_CONNECTION');
        $runtimeConnection = ($input['force'] || ! $configuredRuntime) ? 'mysql' : $configuredRuntime;
        $updates['COREMARKET_RUNTIME_DB_CONNECTION'] = $runtimeConnection;
        if ($input['production_env']) {
            $updates['APP_ENV'] = 'production';
            $updates['APP_DEBUG'] = 'false';
            $updates['APP_URL'] = 'https://'.$input['domain'];
        }

        $backupDirectory = $this->uniqueBackupDirectory();
        File::ensureDirectoryExists($backupDirectory);
        $backupPath = $backupDirectory.DIRECTORY_SEPARATOR.'.env';
        File::copy($envPath, $backupPath);
        $updatedContents = $contents;
        foreach ($updates as $key => $value) {
            $updatedContents = $this->setEnvValue($updatedContents, $key, $value);
        }
        if ($updatedContents !== $contents && File::put($envPath, $updatedContents, true) === false) {
            throw new \RuntimeException('Unable to write receiver configuration to .env.');
        }

        config([
            'coremarket.runtime_sync.token' => $token,
            'coremarket.runtime_snapshot.connection' => $runtimeConnection,
        ]);
        if ($input['production_env']) {
            config(['app.url' => 'https://'.$input['domain']]);
        }
        $runtime = app(CoreMarketRuntimeDatabaseResolver::class)->resolve();
        if (! ($runtime['has_business_settings_table'] ?? false)) {
            $this->warnings[] = 'Runtime snapshot storage is unavailable. Set COREMARKET_RUNTIME_DB_CONNECTION=mysql for single-database installs.';
        }

        return [
            'token_status' => $tokenStatus,
            'token_preview' => $this->maskToken($token),
            'runtime_connection' => $runtimeConnection,
            'runtime_business_settings' => (bool) ($runtime['has_business_settings_table'] ?? false),
            'written' => $updatedContents !== $contents,
            'backup' => $backupPath,
        ];
    }

    private function envValue(string $contents, string $key): ?string
    {
        if (! preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $contents, $matches)) {
            return null;
        }

        $value = trim($matches[1], " \t\n\r\0\x0B\"'");

        return $value === '' ? null : $value;
    }

    private function setEnvValue(string $contents, string $key, string $value): string
    {
        $line = $key.'='.$value;
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
        if (preg_match($pattern, $contents)) {
            return (string) preg_replace($pattern, $line, $contents, 1);
        }

        $newline = str_contains($contents, "\r\n") ? "\r\n" : "\n";

        return rtrim($contents, "\r\n").$newline.$line.$newline;
    }

    private function uniqueBackupDirectory(): string
    {
        $root = (string) config('coremarket.client_setup.backup_root', storage_path('app/backups/client_setup'));
        $directory = $root.DIRECTORY_SEPARATOR.now()->format('Ymd_His');
        $suffix = 0;
        while (File::exists($directory)) {
            $directory = $root.DIRECTORY_SEPARATOR.now()->format('Ymd_His').'_'.(++$suffix);
        }

        return $directory;
    }

    private function maskToken(string $token): string
    {
        return strlen($token) <= 12 ? '[hidden]' : substr($token, 0, 6).'...'.substr($token, -6);
    }

    private function isLocalDomain(string $domain): bool
    {
        $host = strtolower((string) parse_url('https://'.$domain, PHP_URL_HOST));

        return $host === 'localhost' || $host === '127.0.0.1' || str_ends_with($host, '.test') || str_ends_with($host, '.local');
    }

    private function putIfColumn(array &$values, array $columns, string $column, mixed $value): void
    {
        if (isset($columns[$column])) {
            $values[$column] = $value;
        }
    }

    private function putRoleColumn(array &$values, array $columns, ?Role $role): void
    {
        if (! $role || ! isset($columns['role'])) {
            return;
        }
        $type = Schema::getColumnType('users', 'role');
        $values['role'] = in_array($type, ['integer', 'bigint', 'smallint'], true) ? $role->id : $role->name;
    }
}
