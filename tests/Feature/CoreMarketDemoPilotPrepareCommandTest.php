<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CoreMarketDemoPilotPrepareCommandTest extends TestCase
{
    public function test_command_refuses_non_demo_database(): void
    {
        $database = DB::connection()->getDatabaseName();

        $this->assertFalse(str_ends_with(strtolower($database), '_demo'));
        $this->artisan('coremarket:demo-pilot-prepare')
            ->expectsOutput('Pilot demo preparation refused: the active database is not an allowed demo database.')
            ->assertExitCode(1);
    }
}
