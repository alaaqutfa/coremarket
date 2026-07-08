<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CoreMarketGuardDatabaseCommandTest extends TestCase
{
    public function test_guard_database_command_passes_on_full_runtime_database(): void
    {
        Schema::shouldReceive('hasTable')
            ->times(7)
            ->andReturn(true);

        $this->artisan('coremarket:guard-database')
            ->expectsOutput('CoreMarket runtime database guard')
            ->expectsOutputToContain('Database: ')
            ->expectsOutputToContain('Critical CoreMarket runtime tables are present.')
            ->assertExitCode(0);
    }

    public function test_guard_database_command_docs_do_not_hardcode_client_strings(): void
    {
        $blockedDomain = 'www.' . 'petdyzer.com';
        $blockedName = 'Pet' . 'dyzer';
        $files = [
            app_path('Console/Commands/CoreMarketGuardDatabase.php'),
            base_path('docs/clean-baseline-database-strategy.md'),
            base_path('docs/runtime-stabilization-readiness.md'),
        ];

        foreach ($files as $file) {
            $contents = file_get_contents($file);

            $this->assertStringNotContainsString($blockedDomain, $contents);
            $this->assertStringNotContainsString($blockedName, $contents);
        }
    }
}
