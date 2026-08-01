<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReceiverDiagnosticsCommandTest extends TestCase
{
    private string $tempDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDirectory = storage_path('framework/testing/receiver-diagnostics-'.Str::uuid());
        File::ensureDirectoryExists($this->tempDirectory);
        config([
            'coremarket.client_setup.env_path' => $this->tempDirectory.DIRECTORY_SEPARATOR.'.env',
            'coremarket.runtime_snapshot.connection' => 'mysql',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempDirectory);
        parent::tearDown();
    }

    public function test_command_masks_token_and_reports_runtime_connection_without_printing_secret(): void
    {
        $token = str_repeat('ab', 32);
        File::put(config('coremarket.client_setup.env_path'), "COREPILOT_RUNTIME_SYNC_TOKEN={$token}\nCOREPILOT_SYNC_TOKEN={$token}\n");
        config(['coremarket.runtime_sync.token' => $token]);

        $this->artisan('coremarket:receiver-diagnostics')
            ->expectsOutputToContain('Runtime snapshot connection')
            ->expectsOutputToContain('mysql')
            ->expectsOutputToContain(substr($token, 0, 6).'...'.substr($token, -6))
            ->doesntExpectOutputToContain($token)
            ->assertExitCode(0);
    }

    public function test_command_detects_missing_runtime_business_settings_table(): void
    {
        File::put(config('coremarket.client_setup.env_path'), "APP_ENV=testing\n");
        config([
            'coremarket.runtime_sync.token' => null,
            'coremarket.runtime_snapshot.connection' => 'missing_runtime_connection',
        ]);

        $this->artisan('coremarket:receiver-diagnostics')
            ->expectsOutput('Runtime snapshot storage is unavailable. Set COREMARKET_RUNTIME_DB_CONNECTION=mysql for single-database installs.')
            ->expectsOutputToContain('missing_runtime_connection')
            ->assertExitCode(0);
    }
}
