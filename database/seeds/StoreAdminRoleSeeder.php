<?php

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class StoreAdminRoleSeeder extends Seeder
{
    public function run()
    {
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
