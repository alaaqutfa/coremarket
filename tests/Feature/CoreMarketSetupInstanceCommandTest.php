<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CoreMarketSetupInstanceCommandTest extends TestCase
{
    public function test_command_dry_run_does_not_write_to_database(): void
    {
        $before = BusinessSetting::count();

        $this->artisan('coremarket:setup-instance', [
            'instance_id' => 'client-store',
            '--dry-run' => true,
            '--store-name' => 'Client Store',
            '--domain' => 'example-store.com',
        ])
            ->expectsOutput('CoreMarket managed instance setup plan')
            ->assertExitCode(0);

        $this->assertSame($before, BusinessSetting::count());
    }

    public function test_command_apply_mode_stays_blocked_and_safe(): void
    {
        $this->artisan('coremarket:setup-instance', [
            'instance_id' => 'client-store',
            '--apply' => true,
        ])
            ->expectsOutput('Apply mode is not enabled yet. This command is running in dry-run mode only.')
            ->assertExitCode(0);
    }

    public function test_env_example_exists_and_does_not_include_petdyzer(): void
    {
        $path = base_path('.env.example');

        $this->assertFileExists($path);

        $contents = file_get_contents($path);

        $this->assertStringNotContainsString('Petdyzer', $contents);
        $this->assertStringNotContainsString('www.petdyzer.com', $contents);
        $this->assertStringContainsString('COREMARKET_INSTANCE_ID=', $contents);
    }
}
