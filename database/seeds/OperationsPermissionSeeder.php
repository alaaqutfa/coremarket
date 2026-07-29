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
            'supplier_ledger.view', 'supplier_payments.create',
            'purchase_returns.view', 'purchase_returns.create', 'purchase_returns.complete', 'purchase_returns.cancel',
            'sales_returns.view', 'sales_returns.create', 'sales_returns.complete',
            'expenses.view', 'expenses.create', 'expenses.approve',
            'accounting_summary.view',
            'accounting.core.view', 'accounting.accounts.view', 'accounting.journals.view', 'accounting.journals.post', 'accounting.tax.view', 'accounting.tax.audit', 'accounting.general_ledger.view', 'accounting.trial_balance.view', 'accounting.profit_loss.view', 'accounting.events.view',
            'cashboxes.view','cashboxes.create','cashboxes.edit','cash_shifts.view','cash_shifts.open','cash_shifts.close','cash_movements.view','cash_movements.create',
            'pos.view', 'pos.sell', 'pos.receipts.view', 'pos.redeem_loyalty',
            'price_lists.manage',
            'loyalty.view', 'loyalty.rules.manage', 'loyalty.adjust', 'loyalty.movements.view',
            'inventory.view', 'inventory.dashboard.view', 'inventory.stock.view',
            'inventory.stock.adjust', 'inventory.stock.audit', 'inventory.low_stock.view',
            'inventory.barcode_lookup.view',
            'inventory.families.manage',
            'document_templates.view', 'document_templates.manage', 'document_templates.preview',
            'deliveries.view', 'deliveries.assign', 'deliveries.update_status',
            'deliveries.collect_cod', 'deliveries.view_all', 'deliveries.view_assigned',
            'deliveries.settle_cod', 'deliveries.view_cod_summary',
            'sales_invoices.view', 'sales_invoices.export',
            'customer_statements.view', 'customer_statements.export',
            'delivery_notes.view', 'delivery_notes.export',
            'customer_receivables.view', 'customer_receivables.manage',
            'customer_payments.create', 'customer_payments.cancel',
            'customer_ledger.view', 'customer_statements.ledger_export',
            'customer_credit.view', 'customer_credit.manage', 'customer_credit.override_limit',
        ];
    }
}
