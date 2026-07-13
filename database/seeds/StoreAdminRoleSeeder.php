<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class StoreAdminRoleSeeder extends Seeder
{
    public function run()
    {
        $this->call(OperationsPermissionSeeder::class);

        $roleName = config('coremarket.access.store_admin_role', 'store_admin');
        $permissionNames = config('coremarket.access.store_admin_permissions', []);

        $role = Role::firstOrCreate([
            'name' => $roleName,
            'guard_name' => 'web',
        ]);

        $permissions = Permission::query()
            ->whereIn('name', $permissionNames)
            ->get();

        $role->syncPermissions($permissions);
    }
}
