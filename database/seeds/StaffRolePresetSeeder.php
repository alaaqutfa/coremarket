<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class StaffRolePresetSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(OperationsPermissionSeeder::class);
        $this->call(StoreAdminRoleSeeder::class);

        foreach (config('coremarket.access.staff_role_presets', []) as $roleName => $permissionNames) {
            $permissionNames = array_values(array_unique($permissionNames));
            $permissions = Permission::query()
                ->where('guard_name', 'web')
                ->whereIn('name', $permissionNames)
                ->get();
            $missing = array_values(array_diff($permissionNames, $permissions->pluck('name')->all()));

            if ($missing !== []) {
                throw new RuntimeException(sprintf(
                    'Staff role "%s" references missing permissions: %s',
                    $roleName,
                    implode(', ', $missing)
                ));
            }

            Role::query()
                ->firstOrCreate(['name' => $roleName, 'guard_name' => 'web'])
                ->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
