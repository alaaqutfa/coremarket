<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class OperationsPermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('coremarket.access.operations_permissions', self::permissions()) as $name) {
            Permission::query()->firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['section' => 'operations']
            );
        }
    }

    public static function permissions(): array
    {
        return [
            'operations.view', 'inventory_movements.view',
            'suppliers.view', 'suppliers.create', 'suppliers.edit',
            'purchase_orders.view', 'purchase_orders.create', 'purchase_orders.receive',
            'sales_returns.view', 'sales_returns.create', 'sales_returns.complete',
            'expenses.view', 'expenses.create', 'expenses.approve',
            'accounting_summary.view',
            'accounting.core.view', 'accounting.accounts.view', 'accounting.journals.view', 'accounting.journals.post', 'accounting.tax.view', 'accounting.tax.audit',
            'inventory.view', 'inventory.dashboard.view', 'inventory.stock.view',
            'inventory.stock.adjust', 'inventory.stock.audit', 'inventory.low_stock.view',
            'inventory.barcode_lookup.view',
        ];
    }
}
