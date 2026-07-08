<?php

namespace Tests\Feature;

use App\Services\CoreMarketTestingDatabaseService;
use Mockery;
use Tests\TestCase;

class CoreMarketRestoreTestingDatabaseCommandTest extends TestCase
{
    public function test_restore_testing_database_dry_run_uses_demo_baseline_by_default(): void
    {
        $service = Mockery::mock(CoreMarketTestingDatabaseService::class);
        $service->shouldReceive('restorePlan')
            ->once()
            ->with(false)
            ->andReturn($this->fakePlan(base_path('database/base/coremarket_test.sql'), 'demo/testing baseline'));
        $service->shouldReceive('validateRestorePlan')
            ->once()
            ->andReturn([]);

        $this->app->instance(CoreMarketTestingDatabaseService::class, $service);

        $this->artisan('coremarket:restore-testing-database', [
            '--dry-run' => true,
        ])
            ->expectsOutput('CoreMarket testing database restore plan')
            ->expectsOutputToContain('coremarket_test.sql')
            ->expectsOutput('Dry-run complete. No database changes were made.')
            ->assertExitCode(0);
    }

    public function test_restore_testing_database_dry_run_can_use_clean_baseline_flag(): void
    {
        $service = Mockery::mock(CoreMarketTestingDatabaseService::class);
        $service->shouldReceive('restorePlan')
            ->once()
            ->with(true)
            ->andReturn($this->fakePlan(base_path('database/base/coremarket.sql'), 'clean client baseline'));
        $service->shouldReceive('validateRestorePlan')
            ->once()
            ->andReturn([]);

        $this->app->instance(CoreMarketTestingDatabaseService::class, $service);

        $this->artisan('coremarket:restore-testing-database', [
            '--dry-run' => true,
            '--from-clean-baseline' => true,
        ])
            ->expectsOutput('CoreMarket testing database restore plan')
            ->expectsOutputToContain('coremarket.sql')
            ->expectsOutput('Dry-run complete. No database changes were made.')
            ->assertExitCode(0);
    }

    public function test_restore_testing_database_docs_do_not_hardcode_petdyzer_strings(): void
    {
        $blockedDomain = 'www.' . 'petdyzer.com';
        $blockedName = 'Pet' . 'dyzer';
        $files = [
            app_path('Console/Commands/CoreMarketRestoreTestingDatabase.php'),
            app_path('Services/CoreMarketTestingDatabaseService.php'),
            base_path('docs/database-baseline-workflow.md'),
        ];

        foreach ($files as $file) {
            $contents = file_get_contents($file);

            $this->assertStringNotContainsString($blockedDomain, $contents);
            $this->assertStringNotContainsString($blockedName, $contents);
        }
    }

    protected function fakePlan(string $baselinePath, string $sourceLabel): array
    {
        return [
            'testing_database' => 'coremarket_testing',
            'runtime_database' => 'coremarket_runtime',
            'host' => 'localhost',
            'port' => '3306',
            'username' => 'root',
            'password' => '',
            'baseline_source' => $sourceLabel === 'clean client baseline' ? 'clean_client_baseline' : 'demo_testing_baseline',
            'baseline_source_label' => $sourceLabel,
            'baseline_path' => $baselinePath,
            'baseline_exists' => true,
            'baseline_size' => 1024,
            'mysql_binary' => 'C:\xampp\mysql\bin\mysql.exe',
        ];
    }
}
