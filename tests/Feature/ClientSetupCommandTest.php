<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class ClientSetupCommandTest extends TestCase
{
    use DatabaseTransactions;

    private string $tempDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDirectory = storage_path('framework/testing/client-setup-'.Str::uuid());
        File::ensureDirectoryExists($this->tempDirectory);
        config([
            'coremarket.client_setup.env_path' => $this->tempDirectory.DIRECTORY_SEPARATOR.'.env',
            'coremarket.client_setup.backup_root' => $this->tempDirectory.DIRECTORY_SEPARATOR.'backups',
            'coremarket.runtime_snapshot.connection' => 'mysql',
        ]);
        File::put(config('coremarket.client_setup.env_path'), "APP_ENV=testing\nAPP_DEBUG=true\nAPP_URL=http://localhost\n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempDirectory);
        parent::tearDown();
    }

    public function test_legacy_admin_email_creates_support_admin_with_deprecation_warning(): void
    {
        $email = $this->uniqueEmail('support');

        $this->artisan('coremarket:client-setup', $this->legacyOptions($email))
            ->expectsOutput('Deprecated: --admin-email is treated as support admin. Prefer --support-admin-email or --client-admin-email.')
            ->assertExitCode(0);

        $admin = User::query()->where('email', $email)->firstOrFail();
        $this->assertSame('admin', $admin->user_type);
        $this->assertTrue(Hash::check('SecurePass123!', $admin->password));
        $this->assertTrue($admin->hasRole('Super Admin'));
        $this->assertDatabaseHas('permissions', ['name' => 'operations.view', 'guard_name' => 'web']);
        $this->assertStringNotContainsString('COREPILOT_RUNTIME_SYNC_TOKEN=', File::get(config('coremarket.client_setup.env_path')));
    }

    public function test_command_creates_support_and_client_admins_separately(): void
    {
        $supportEmail = $this->uniqueEmail('support');
        $clientEmail = $this->uniqueEmail('client');

        $this->artisan('coremarket:client-setup', $this->separateAdminOptions($supportEmail, $clientEmail))
            ->assertExitCode(0);

        $support = User::query()->where('email', $supportEmail)->firstOrFail();
        $client = User::query()->where('email', $clientEmail)->firstOrFail();
        $this->assertSame('admin', $support->user_type);
        $this->assertSame('staff', $client->user_type);
        $this->assertTrue($support->hasRole('Super Admin'));
        $this->assertTrue($client->hasRole('store_admin'));
        $this->assertDatabaseHas('staff', [
            'user_id' => $client->id,
            'role_id' => $client->roles()->where('name', 'store_admin')->firstOrFail()->id,
        ]);
        $this->assertTrue(Hash::check('SupportPass123!', $support->password));
        $this->assertTrue(Hash::check('ClientPass123!', $client->password));
    }

    public function test_existing_support_and_client_passwords_are_not_overwritten_without_force(): void
    {
        $supportEmail = $this->insertAdmin('existing-support', 'OriginalSupport123!');
        $clientEmail = $this->insertAdmin('existing-client', 'OriginalClient123!');

        $this->artisan('coremarket:client-setup', $this->separateAdminOptions($supportEmail, $clientEmail))
            ->assertExitCode(0);

        $this->assertTrue(Hash::check('OriginalSupport123!', DB::table('users')->where('email', $supportEmail)->value('password')));
        $this->assertTrue(Hash::check('OriginalClient123!', DB::table('users')->where('email', $clientEmail)->value('password')));
        $this->assertSame('admin', DB::table('users')->where('email', $supportEmail)->value('user_type'));
        $this->assertSame('staff', DB::table('users')->where('email', $clientEmail)->value('user_type'));
    }

    public function test_client_admin_full_access_requires_explicit_option(): void
    {
        $supportEmail = $this->uniqueEmail('support-full');
        $clientEmail = $this->uniqueEmail('client-full');
        $options = array_merge($this->separateAdminOptions($supportEmail, $clientEmail), [
            '--client-admin-full-access' => true,
        ]);

        $this->artisan('coremarket:client-setup', $options)
            ->expectsOutput('Client Admin full access is enabled. Use this only for an internal CorePilot-owned store.')
            ->assertExitCode(0);

        $client = User::query()->where('email', $clientEmail)->firstOrFail();
        $this->assertSame('admin', $client->user_type);
        $this->assertTrue($client->hasRole('Super Admin'));
    }

    public function test_repair_admin_access_promotes_hotfix82_support_and_keeps_client_limited(): void
    {
        $supportEmail = $this->insertAdmin('repair-support', 'OriginalSupport123!', 'staff');
        $clientEmail = $this->insertAdmin('repair-client', 'OriginalClient123!', 'admin');
        $ownerRole = Role::query()->firstOrCreate(['name' => 'owner_general_manager', 'guard_name' => 'web']);
        $legacySupport = User::query()->where('email', $supportEmail)->firstOrFail();
        $legacySupport->syncRoles([$ownerRole]);
        DB::table('staff')->insert([
            'user_id' => $legacySupport->id,
            'role_id' => $ownerRole->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $options = array_merge($this->separateAdminOptions($supportEmail, $clientEmail), [
            '--repair-admin-access' => true,
        ]);
        $this->artisan('coremarket:client-setup', $options)->assertExitCode(0);

        $support = User::query()->where('email', $supportEmail)->firstOrFail();
        $client = User::query()->where('email', $clientEmail)->firstOrFail();
        $this->assertSame('admin', $support->user_type);
        $this->assertTrue($support->hasRole('Super Admin'));
        $this->assertSame(
            $support->roles()->where('name', 'Super Admin')->firstOrFail()->id,
            DB::table('staff')->where('user_id', $support->id)->value('role_id')
        );
        $this->assertSame('staff', $client->user_type);
        $this->assertTrue($client->hasRole('store_admin'));
        $this->assertFalse($client->hasRole('Super Admin'));
        $this->assertTrue(Hash::check('OriginalSupport123!', $support->password));
        $this->assertTrue(Hash::check('OriginalClient123!', $client->password));
    }

    public function test_support_admin_has_full_gate_and_admin_dashboard_access(): void
    {
        $email = $this->uniqueEmail('dashboard-support');
        $this->artisan('coremarket:client-setup', $this->supportOptions($email))->assertExitCode(0);

        $support = User::query()->where('email', $email)->firstOrFail();
        $this->assertTrue($support->can('system_update'));
        $this->actingAs($support)->get('/admin')->assertOk();
    }

    public function test_command_enables_enterprise_settings_idempotently(): void
    {
        $email = $this->uniqueEmail('enterprise');
        $options = array_merge($this->supportOptions($email), [
            '--enable-enterprise' => true,
            '--force' => true,
        ]);

        $this->artisan('coremarket:client-setup', $options)->assertExitCode(0);
        $this->artisan('coremarket:client-setup', $options)->assertExitCode(0);

        $this->assertSame(1, DB::table('users')->where('email', $email)->count());
        $this->assertSame('enterprise', DB::table('business_settings')->where('type', 'coremarket.plan')->whereNull('lang')->value('value'));
        $this->assertSame('1', DB::table('business_settings')->where('type', 'inventory.branch_inventory_enabled')->whereNull('lang')->value('value'));
    }

    public function test_command_writes_canonical_token_and_mysql_runtime_connection_without_exposing_token(): void
    {
        $output = new BufferedOutput();
        $exitCode = Artisan::call('coremarket:client-setup', array_merge($this->supportOptions($this->uniqueEmail('token')), [
            '--write-env' => true,
        ]), $output);

        $this->assertSame(0, $exitCode);
        $commandOutput = $output->fetch();
        $contents = File::get(config('coremarket.client_setup.env_path'));
        preg_match('/^COREPILOT_RUNTIME_SYNC_TOKEN=([a-f0-9]{64})$/m', $contents, $matches);
        $this->assertArrayHasKey(1, $matches);
        $this->assertStringContainsString('COREPILOT_SYNC_TOKEN='.$matches[1], $contents);
        $this->assertStringContainsString('COREMARKET_RUNTIME_DB_CONNECTION=mysql', $contents);
        $this->assertStringNotContainsString($matches[1], $commandOutput);
        $this->assertStringContainsString(substr($matches[1], 0, 6).'...'.substr($matches[1], -6), $commandOutput);
        $this->assertNotEmpty(File::directories(config('coremarket.client_setup.backup_root')));
    }

    public function test_command_copies_legacy_token_to_canonical_key(): void
    {
        $legacyToken = str_repeat('a1', 32);
        File::append(config('coremarket.client_setup.env_path'), 'COREPILOT_SYNC_TOKEN='.$legacyToken."\n");

        $this->artisan('coremarket:client-setup', array_merge($this->supportOptions($this->uniqueEmail('legacy-token')), [
            '--write-env' => true,
        ]))->assertExitCode(0);

        $contents = File::get(config('coremarket.client_setup.env_path'));
        $this->assertStringContainsString('COREPILOT_RUNTIME_SYNC_TOKEN='.$legacyToken, $contents);
        $this->assertSame(1, substr_count($contents, 'COREPILOT_SYNC_TOKEN='.$legacyToken));
    }

    public function test_production_env_updates_only_the_isolated_env_file(): void
    {
        $this->artisan('coremarket:client-setup', array_merge($this->supportOptions($this->uniqueEmail('production')), [
            '--write-env' => true,
            '--production-env' => true,
        ]))->assertExitCode(0);

        $contents = File::get(config('coremarket.client_setup.env_path'));
        $this->assertStringContainsString("APP_ENV=production\n", $contents);
        $this->assertStringContainsString("APP_DEBUG=false\n", $contents);
        $this->assertStringContainsString("APP_URL=https://client.example.test\n", $contents);
    }

    public function test_final_output_contains_corepilotos_connection_hints(): void
    {
        $this->artisan('coremarket:client-setup', $this->supportOptions($this->uniqueEmail('hints')))
            ->expectsOutputToContain('Next CorePilotOS values')
            ->expectsOutputToContain('Token env key: COREPILOT_RUNTIME_SYNC_TOKEN')
            ->expectsOutputToContain('Header: X-CorePilot-Sync-Token')
            ->expectsOutputToContain('php artisan coremarket:receiver-diagnostics')
            ->assertExitCode(0);
    }

    public function test_command_fails_safely_when_business_settings_is_missing(): void
    {
        config(['coremarket.client_setup.required_table_overrides.business_settings' => false]);
        $email = $this->uniqueEmail('missing-settings');

        $this->artisan('coremarket:client-setup', $this->supportOptions($email))
            ->expectsOutput('Required business_settings table is missing.')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['email' => $email]);
    }

    public function test_command_warns_and_creates_admin_when_role_system_is_unavailable(): void
    {
        config([
            'coremarket.client_setup.required_table_overrides.roles' => false,
            'coremarket.client_setup.required_table_overrides.permissions' => false,
        ]);
        $email = $this->uniqueEmail('no-role');

        $this->artisan('coremarket:client-setup', $this->supportOptions($email))
            ->expectsOutput('roles/permissions tables are unavailable; permission seeders and role assignment were skipped.')
            ->assertExitCode(0);

        $admin = User::query()->where('email', $email)->firstOrFail();
        $this->assertSame('admin', $admin->user_type);
        $this->assertFalse($admin->roles()->exists());
    }

    private function supportOptions(string $email): array
    {
        return [
            '--project' => 'Test Client',
            '--support-admin-email' => $email,
            '--support-admin-password' => 'SecurePass123!',
            '--domain' => 'client.example.test',
            '--plan' => 'enterprise',
        ];
    }

    private function legacyOptions(string $email): array
    {
        return [
            '--project' => 'Test Client',
            '--admin-email' => $email,
            '--password' => 'SecurePass123!',
            '--domain' => 'client.example.test',
            '--plan' => 'enterprise',
        ];
    }

    private function separateAdminOptions(string $supportEmail, string $clientEmail): array
    {
        return [
            '--project' => 'Test Client',
            '--support-admin-email' => $supportEmail,
            '--support-admin-password' => 'SupportPass123!',
            '--client-admin-email' => $clientEmail,
            '--client-admin-password' => 'ClientPass123!',
            '--domain' => 'client.example.test',
            '--plan' => 'enterprise',
        ];
    }

    private function insertAdmin(string $prefix, string $password, string $userType = 'admin'): string
    {
        $email = $this->uniqueEmail($prefix);
        DB::table('users')->insert([
            'name' => 'Existing Admin',
            'email' => $email,
            'password' => Hash::make($password),
            'user_type' => $userType,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $email;
    }

    private function uniqueEmail(string $prefix): string
    {
        return $prefix.'-'.Str::lower(Str::random(8)).'@example.test';
    }
}
