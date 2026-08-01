<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\OperationsPermissionSeeder;
use Database\Seeders\StaffRolePresetSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Throwable;

class CoreMarketClientSetupCommand extends Command
{
    protected $signature = 'coremarket:client-setup
                            {--project= : Client project or store name}
                            {--admin-email= : First administrator email}
                            {--email= : Alias for --admin-email}
                            {--password= : First administrator password}
                            {--pass= : Alias for --password}
                            {--domain= : Client domain without secrets}
                            {--plan=enterprise : Applied plan label}
                            {--write-env : Back up and write COREPILOT_SYNC_TOKEN to .env}
                            {--enable-enterprise : Enable the approved Enterprise feature settings}
                            {--force : Allow password replacement, token rotation, or conversion of an existing non-admin user}';

    protected $description = 'Safely prepare permissions, the first Admin, client settings, and CorePilot sync token';

    private array $warnings = [];

    public function handle(): int
    {
        $input = $this->validatedInput();
        if ($input === null) {
            return self::FAILURE;
        }

        $preflight = $this->preflight();
        $this->printPreflight($preflight);
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

                $admin = $this->ensureAdmin($input, $preflight['role_system_ready']);
                $settings = $input['enable_enterprise']
                    ? $this->enableEnterpriseSettings($input)
                    : ['enabled' => false, 'updated' => 0];

                return compact('admin', 'settings', 'seeders');
            });
        } catch (Throwable $exception) {
            $this->error('Client setup failed before environment update: '.$exception->getMessage());
            $this->warn('Database changes from this command were rolled back.');

            return self::FAILURE;
        }

        try {
            $token = $this->prepareSyncToken($input['write_env'], $input['force']);
        } catch (Throwable $exception) {
            $this->error('Client setup database changes completed, but .env token handling failed: '.$exception->getMessage());
            $this->warn('Restore the reported .env backup if a partial filesystem write is suspected.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('CoreMarket client setup completed.');
        $this->table(['Result', 'Value'], [
            ['Admin email', $input['admin_email']],
            ['Admin status', $result['admin']['status']],
            ['Assigned role', $result['admin']['role'] ?? '[role system unavailable]'],
            ['Seeders', $result['seeders']],
            ['Enterprise enabled', $result['settings']['enabled'] ? 'yes' : 'no'],
            ['Enterprise settings updated', $result['settings']['updated']],
            ['Sync token status', $token['status']],
            ['Sync token preview', $token['preview']],
            ['.env written', $token['written'] ? 'yes' : 'no'],
            ['.env backup', $token['backup'] ?? '[not required]'],
        ]);

        foreach ($this->warnings as $warning) {
            $this->warn($warning);
        }
        if (! $token['written']) {
            $this->warn('Set a secure 64-character hexadecimal COREPILOT_SYNC_TOKEN in .env outside command logs.');
        }

        $this->line('Next steps');
        $this->line('1. php artisan optimize:clear');
        $this->line('2. php artisan storage:link');
        $this->line('3. php artisan coremarket:branch-inventory-initialize --dry-run');
        $this->line('4. Login: '.rtrim((string) config('app.url'), '/').'/login');

        return self::SUCCESS;
    }

    private function validatedInput(): ?array
    {
        $adminEmail = trim((string) ($this->option('admin-email') ?: $this->option('email')));
        $aliasEmail = trim((string) $this->option('email'));
        $primaryEmail = trim((string) $this->option('admin-email'));
        $password = (string) ($this->option('password') ?: $this->option('pass'));
        $aliasPassword = (string) $this->option('pass');
        $primaryPassword = (string) $this->option('password');
        $project = trim((string) $this->option('project'));
        $domain = trim((string) $this->option('domain'));
        $plan = strtolower(trim((string) $this->option('plan')));
        $errors = [];

        if ($primaryEmail !== '' && $aliasEmail !== '' && strcasecmp($primaryEmail, $aliasEmail) !== 0) {
            $errors[] = '--admin-email and --email must match when both are supplied.';
        }
        if ($primaryPassword !== '' && $aliasPassword !== '' && $primaryPassword !== $aliasPassword) {
            $errors[] = '--password and --pass must match when both are supplied.';
        }
        if ($project === '') {
            $errors[] = '--project is required.';
        }
        if (! filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid --admin-email or --email is required.';
        }
        if ($password !== '' && strlen($password) < 8) {
            $errors[] = 'The supplied Admin password must contain at least 8 characters.';
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

        foreach ($errors as $error) {
            $this->error($error);
        }

        return $errors === [] ? [
            'project' => $project,
            'admin_email' => strtolower($adminEmail),
            'password' => $password,
            'domain' => $domain,
            'plan' => $plan,
            'write_env' => (bool) $this->option('write-env'),
            'enable_enterprise' => (bool) $this->option('enable-enterprise'),
            'force' => (bool) $this->option('force'),
        ] : null;
    }

    private function preflight(): array
    {
        $errors = [];
        $configuredDatabase = config('database.connections.'.config('database.default').'.database');
        $actualDatabase = null;
        $selectedDatabase = null;

        try {
            $actualDatabase = DB::connection()->getDatabaseName();
            $selectedDatabase = DB::selectOne('SELECT DATABASE() AS database_name')->database_name ?? null;
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
        return [
            'ready' => $errors === [] && $connectionReady,
            'app_env' => app()->environment(),
            'app_url' => config('app.url'),
            'configured_database' => $configuredDatabase,
            'actual_database' => $actualDatabase,
            'selected_database' => $selectedDatabase,
            'users_count' => $usersTable ? DB::table('users')->count() : null,
            'business_settings' => $settingsTable,
            'roles' => $rolesTable,
            'permissions' => $permissionsTable,
            'role_system_ready' => $rolesTable && $permissionsTable,
            'errors' => $errors,
        ];
    }

    private function printPreflight(array $preflight): void
    {
        $this->info('CoreMarket client setup preflight');
        $this->table(['Check', 'Value'], [
            ['APP_ENV', $preflight['app_env']],
            ['APP_URL', $preflight['app_url']],
            ['Configured DB_DATABASE', $preflight['configured_database'] ?: '[missing]'],
            ['Actual connection database', $preflight['actual_database'] ?: '[missing]'],
            ['Selected MySQL database', $preflight['selected_database'] ?: '[missing]'],
            ['Users before', $preflight['users_count'] ?? '[table missing]'],
            ['business_settings table', $preflight['business_settings'] ? 'present' : 'missing'],
            ['roles table', $preflight['roles'] ? 'present' : 'missing'],
            ['permissions table', $preflight['permissions'] ? 'present' : 'missing'],
        ]);
    }

    private function runSeeder(string $seeder): void
    {
        $exitCode = $this->callSilent('db:seed', [
            '--class' => $seeder,
            '--force' => true,
        ]);
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

    private function ensureAdmin(array $input, bool $roleSystemReady): array
    {
        $existing = DB::table('users')->where('email', $input['admin_email'])->first();
        if ($existing && isset($existing->user_type) && ! in_array($existing->user_type, ['admin', 'staff'], true) && ! $input['force']) {
            throw new \RuntimeException('The Admin email belongs to a non-admin user. Use --force only after reviewing that account.');
        }
        if (! $existing && $input['password'] === '') {
            throw new \RuntimeException('A password is required when creating the first Admin.');
        }

        $role = $roleSystemReady ? $this->resolveAdminRole() : null;
        $columns = array_flip(Schema::getColumnListing('users'));
        $now = now();
        $displayName = $input['project'].' Administrator';

        if (! $existing) {
            $values = [];
            $this->putIfColumn($values, $columns, 'name', $displayName);
            $this->putIfColumn($values, $columns, 'f_name', $input['project']);
            $this->putIfColumn($values, $columns, 'l_name', 'Administrator');
            $this->putIfColumn($values, $columns, 'email', $input['admin_email']);
            $this->putIfColumn($values, $columns, 'phone', '0000000000');
            $this->putIfColumn($values, $columns, 'password', Hash::make($input['password']));
            $this->putIfColumn($values, $columns, 'user_type', 'admin');
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
            if ($input['force']) {
                $this->putIfColumn($updates, $columns, 'user_type', 'admin');
                $this->putIfColumn($updates, $columns, 'status', 1);
                $this->putIfColumn($updates, $columns, 'is_active', 1);
                $this->putIfColumn($updates, $columns, 'banned', 0);
                $this->putRoleColumn($updates, $columns, $role);
                if ($input['password'] !== '') {
                    $this->putIfColumn($updates, $columns, 'password', Hash::make($input['password']));
                }
            }
            if ($updates !== []) {
                $this->putIfColumn($updates, $columns, 'updated_at', $now);
                DB::table('users')->where('id', $userId)->update($updates);
            }
            $status = $input['force'] ? 'existing account updated' : 'existing account kept; password unchanged';
        }

        if ($role && Schema::hasTable('model_has_roles')) {
            $user = User::query()->findOrFail($userId);
            $user->syncRoles([$role]);
        } else {
            $this->warnings[] = 'Admin was saved, but no owner_general_manager/store_admin role could be assigned.';
        }

        return ['id' => $userId, 'status' => $status, 'role' => $role?->name];
    }

    private function resolveAdminRole(): ?Role
    {
        if (! Schema::hasTable('roles')) {
            return null;
        }

        return Role::query()->where('guard_name', 'web')->where('name', 'owner_general_manager')->first()
            ?: Role::query()->where('guard_name', 'web')->where('name', 'store_admin')->first();
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

    private function prepareSyncToken(bool $writeEnv, bool $force): array
    {
        $generated = bin2hex(random_bytes(32));
        if (! $writeEnv) {
            return [
                'status' => 'generated for manual secure configuration; not persisted',
                'preview' => $this->maskToken($generated),
                'written' => false,
                'backup' => null,
            ];
        }

        $envPath = (string) config('coremarket.client_setup.env_path', app()->environmentFilePath());
        if (! File::isFile($envPath)) {
            throw new \RuntimeException('.env file was not found at the configured environment path.');
        }
        $contents = File::get($envPath);
        preg_match('/^COREPILOT_SYNC_TOKEN=(.*)$/m', $contents, $matches);
        $existing = isset($matches[1]) ? trim($matches[1], " \t\n\r\0\x0B\"'") : null;
        $token = $existing && ! $force ? $existing : $generated;
        $status = $existing && ! $force ? 'kept existing token' : ($existing ? 'rotated token' : 'created token');
        $backupDirectory = $this->uniqueBackupDirectory();
        File::ensureDirectoryExists($backupDirectory);
        $backupPath = $backupDirectory.DIRECTORY_SEPARATOR.'.env';
        File::copy($envPath, $backupPath);

        if (! $existing || $force) {
            $line = 'COREPILOT_SYNC_TOKEN='.$token;
            if (preg_match('/^COREPILOT_SYNC_TOKEN=.*$/m', $contents)) {
                $contents = preg_replace('/^COREPILOT_SYNC_TOKEN=.*$/m', $line, $contents, 1);
            } else {
                $newline = str_contains($contents, "\r\n") ? "\r\n" : "\n";
                $contents = rtrim($contents, "\r\n").$newline.$line.$newline;
            }
            if (File::put($envPath, $contents, true) === false) {
                throw new \RuntimeException('Unable to write COREPILOT_SYNC_TOKEN to .env.');
            }
        }

        return [
            'status' => $status,
            'preview' => $this->maskToken($token),
            'written' => ! $existing || $force,
            'backup' => $backupPath,
        ];
    }

    private function uniqueBackupDirectory(): string
    {
        $root = (string) config(
            'coremarket.client_setup.backup_root',
            storage_path('app/backups/client_setup')
        );
        $directory = $root.DIRECTORY_SEPARATOR.now()->format('Ymd_His');
        $suffix = 0;
        while (File::exists($directory)) {
            $suffix++;
            $directory = $root.DIRECTORY_SEPARATOR.now()->format('Ymd_His').'_'.$suffix;
        }

        return $directory;
    }

    private function maskToken(string $token): string
    {
        return strlen($token) <= 12 ? '[hidden]' : substr($token, 0, 6).'...'.substr($token, -6);
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
