<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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
        ]);
        File::put(config('coremarket.client_setup.env_path'), "APP_ENV=testing\n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempDirectory);
        parent::tearDown();
    }

    public function test_command_creates_first_admin_and_runs_seeders(): void
    {
        $email = 'client-admin-'.Str::lower(Str::random(8)).'@example.test';

        $this->artisan('coremarket:client-setup', $this->commandOptions($email))
            ->assertExitCode(0);

        $admin = User::query()->where('email', $email)->firstOrFail();
        $this->assertSame('admin', $admin->user_type);
        $this->assertTrue(Hash::check('SecurePass123!', $admin->password));
        $this->assertTrue($admin->hasRole('owner_general_manager'));
        $this->assertDatabaseHas('permissions', ['name' => 'operations.view', 'guard_name' => 'web']);
        $this->assertDatabaseHas('roles', ['name' => 'store_admin', 'guard_name' => 'web']);
        $this->assertStringNotContainsString('COREPILOT_SYNC_TOKEN=', File::get(config('coremarket.client_setup.env_path')));
    }

    public function test_existing_admin_password_is_not_overwritten_without_force(): void
    {
        $email = 'existing-admin-'.Str::lower(Str::random(8)).'@example.test';
        DB::table('users')->insert([
            'name' => 'Existing Admin',
            'email' => $email,
            'password' => Hash::make('OriginalPass123!'),
            'user_type' => 'admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('coremarket:client-setup', $this->commandOptions($email))
            ->assertExitCode(0);

        $password = DB::table('users')->where('email', $email)->value('password');
        $this->assertTrue(Hash::check('OriginalPass123!', $password));
        $this->assertFalse(Hash::check('SecurePass123!', $password));
    }

    public function test_command_enables_enterprise_settings_idempotently(): void
    {
        $email = 'enterprise-admin-'.Str::lower(Str::random(8)).'@example.test';
        $options = array_merge($this->commandOptions($email), [
            '--enable-enterprise' => true,
            '--force' => true,
        ]);

        $this->artisan('coremarket:client-setup', $options)->assertExitCode(0);
        $this->artisan('coremarket:client-setup', $options)->assertExitCode(0);

        $this->assertSame(1, DB::table('users')->where('email', $email)->count());
        $this->assertSame(1, DB::table('business_settings')->where('type', 'coremarket.plan')->whereNull('lang')->count());
        $this->assertSame('enterprise', DB::table('business_settings')->where('type', 'coremarket.plan')->whereNull('lang')->value('value'));
        $this->assertSame('1', DB::table('business_settings')->where('type', 'inventory.branch_inventory_enabled')->whereNull('lang')->value('value'));
        $this->assertSame('branch_price_first', DB::table('business_settings')->where('type', 'pricing.branch_pricing_priority')->whereNull('lang')->value('value'));
    }

    public function test_command_writes_a_masked_token_only_to_isolated_test_env(): void
    {
        $email = 'token-admin-'.Str::lower(Str::random(8)).'@example.test';
        $output = new BufferedOutput();
        $exitCode = Artisan::call('coremarket:client-setup', array_merge($this->commandOptions($email), [
            '--write-env' => true,
        ]), $output);

        $this->assertSame(0, $exitCode);
        $commandOutput = $output->fetch();
        $contents = File::get(config('coremarket.client_setup.env_path'));
        preg_match('/^COREPILOT_SYNC_TOKEN=([a-f0-9]{64})$/m', $contents, $matches);
        $this->assertArrayHasKey(1, $matches);
        $this->assertStringNotContainsString($matches[1], $commandOutput);
        $this->assertStringContainsString(substr($matches[1], 0, 6).'...'.substr($matches[1], -6), $commandOutput);
        $backupDirectories = File::directories(config('coremarket.client_setup.backup_root'));
        $this->assertNotEmpty($backupDirectories);
        $this->assertFileExists($backupDirectories[0].DIRECTORY_SEPARATOR.'.env');
    }

    public function test_command_fails_safely_when_business_settings_is_missing(): void
    {
        config(['coremarket.client_setup.required_table_overrides.business_settings' => false]);

        $email = 'missing-settings-'.Str::lower(Str::random(8)).'@example.test';
        $this->artisan('coremarket:client-setup', $this->commandOptions($email))
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

        $email = 'no-role-admin-'.Str::lower(Str::random(8)).'@example.test';
        $this->artisan('coremarket:client-setup', $this->commandOptions($email))
            ->expectsOutput('roles/permissions tables are unavailable; permission seeders and role assignment were skipped.')
            ->assertExitCode(0);

        $admin = User::query()->where('email', $email)->firstOrFail();
        $this->assertSame('admin', $admin->user_type);
        $this->assertFalse($admin->roles()->exists());
        $this->assertDatabaseHas('users', [
            'email' => $email,
            'user_type' => 'admin',
        ]);
    }

    private function commandOptions(string $email): array
    {
        return [
            '--project' => 'Test Client',
            '--admin-email' => $email,
            '--password' => 'SecurePass123!',
            '--domain' => 'client.example.test',
            '--plan' => 'enterprise',
        ];
    }
}
