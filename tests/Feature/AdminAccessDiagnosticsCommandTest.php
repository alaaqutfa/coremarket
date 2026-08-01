<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminAccessDiagnosticsCommandTest extends TestCase
{
    use DatabaseTransactions;

    public function test_command_detects_support_system_admin_without_printing_password(): void
    {
        $password = 'NeverPrintThisPassword123!';
        $user = $this->createUser('admin', $password);
        $user->syncRoles([Role::query()->firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web'])]);

        $this->artisan('coremarket:admin-access-diagnostics', ['--email' => $user->email])
            ->expectsOutputToContain('system_admin')
            ->expectsOutputToContain('Has full admin controls')
            ->doesntExpectOutputToContain($password)
            ->assertExitCode(0);
    }

    public function test_command_detects_client_store_admin(): void
    {
        $user = $this->createUser('staff', 'ClientPassword123!');
        $role = Role::query()->firstOrCreate(['name' => 'store_admin', 'guard_name' => 'web']);
        $user->syncRoles([$role]);
        DB::table('staff')->insert([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('coremarket:admin-access-diagnostics', ['--email' => $user->email])
            ->expectsOutputToContain('store_admin/client_admin')
            ->expectsOutputToContain((string) $role->id)
            ->assertExitCode(0);
    }

    private function createUser(string $userType, string $password): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Diagnostics Admin',
            'email' => Str::lower(Str::random(10)).'@example.test',
            'password' => Hash::make($password),
            'user_type' => $userType,
            'banned' => 0,
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::query()->findOrFail($id);
    }
}
