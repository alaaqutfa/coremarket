<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CoreMarketAdminAccessDiagnosticsCommand extends Command
{
    protected $signature = 'coremarket:admin-access-diagnostics {--email= : Support or client Admin email}';

    protected $description = 'Read-only diagnostics for CoreMarket system and store administrator access';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->option('email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('A valid --email is required.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();
        if (! $user) {
            $this->table(['Field', 'Value'], [
                ['Email', $email],
                ['User exists', 'no'],
                ['Detected access type', 'unknown'],
            ]);

            return self::FAILURE;
        }

        $roleNames = Schema::hasTable('model_has_roles') && Schema::hasTable('roles')
            ? $user->roles()->pluck('name')->all()
            : [];
        $staff = Schema::hasTable('staff')
            ? DB::table('staff')->where('user_id', $user->id)->first()
            : null;
        $roleId = $staff->role_id ?? (Schema::hasColumn('users', 'role_id') ? $user->role_id : null);
        $banned = (bool) ($user->banned ?? false);
        $verified = filled($user->email_verified_at);
        $accessType = $this->accessType($user, $roleNames);
        $canAccessDashboard = in_array($user->user_type, ['admin', 'staff'], true) && ! $banned;
        $hasFullAdminControls = $user->user_type === 'admin' && in_array('Super Admin', $roleNames, true) && ! $banned;
        $baseUrl = rtrim((string) config('app.url'), '/');

        $this->info('CoreMarket Admin access diagnostics');
        $this->table(['Field', 'Value'], [
            ['Email', $email],
            ['User exists', 'yes'],
            ['user_type', $user->user_type ?? '[missing]'],
            ['role_id', $roleId ?? '[missing]'],
            ['Role name(s)', $roleNames ? implode(', ', $roleNames) : '[none]'],
            ['Banned', $banned ? 'yes' : 'no'],
            ['Email verified', $verified ? 'yes' : 'no'],
            ['Detected access type', $accessType],
            ['Can access admin dashboard', $canAccessDashboard ? 'yes' : 'no'],
            ['Has full admin controls', $hasFullAdminControls ? 'yes' : 'no'],
            ['Expected login URL', $baseUrl.'/login'],
            ['Expected admin URL', $baseUrl.'/admin'],
        ]);
        $this->line('Read-only: no user, role, password, token, or environment value was changed.');

        return self::SUCCESS;
    }

    private function accessType(User $user, array $roleNames): string
    {
        if ($user->user_type === 'admin' && in_array('Super Admin', $roleNames, true)) {
            return 'system_admin';
        }
        if ($user->user_type === 'staff' && in_array('store_admin', $roleNames, true)) {
            return 'store_admin/client_admin';
        }
        if ($user->user_type === 'customer') {
            return 'customer';
        }
        if ($user->user_type === 'seller') {
            return 'seller';
        }

        return 'unknown';
    }
}
