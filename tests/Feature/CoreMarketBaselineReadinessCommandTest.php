<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CoreMarketBaselineReadinessCommandTest extends TestCase
{
    public function test_baseline_readiness_command_runs_in_read_only_mode(): void
    {
        $before = [
            'users' => DB::table('users')->count(),
            'business_settings' => DB::table('business_settings')->count(),
            'products' => DB::table('products')->count(),
            'uploads' => DB::table('uploads')->count(),
        ];

        $this->artisan('coremarket:audit-baseline-readiness')
            ->expectsOutput('CoreMarket baseline readiness audit')
            ->expectsOutput('Read-only audit complete. No database changes were made.')
            ->assertExitCode(0);

        $after = [
            'users' => DB::table('users')->count(),
            'business_settings' => DB::table('business_settings')->count(),
            'products' => DB::table('products')->count(),
            'uploads' => DB::table('uploads')->count(),
        ];

        $this->assertSame($before, $after);
    }

    public function test_baseline_readiness_command_reports_schema_drift_and_summary_sections(): void
    {
        $this->artisan('coremarket:audit-baseline-readiness')
            ->expectsOutput('Summary')
            ->expectsOutputToContain('[FAIL] Schema drift')
            ->expectsOutput('Table counts')
            ->expectsOutput('Required baseline settings')
            ->expectsOutput('Managed baseline flags')
            ->expectsOutput('Legacy branding warnings')
            ->expectsOutput('Schema drift')
            ->assertExitCode(0);
    }

    public function test_baseline_readiness_command_and_docs_do_not_hardcode_petdyzer_strings(): void
    {
        $blockedDomain = 'www.' . 'petdyzer.com';
        $blockedName = 'Pet' . 'dyzer';
        $files = [
            app_path('Console/Commands/CoreMarketAuditBaselineReadiness.php'),
            app_path('Services/CoreMarketBaselineReadinessService.php'),
            base_path('docs/clean-baseline-database-strategy.md'),
        ];

        foreach ($files as $file) {
            $contents = file_get_contents($file);

            $this->assertStringNotContainsString($blockedDomain, $contents);
            $this->assertStringNotContainsString($blockedName, $contents);
        }
    }
}
