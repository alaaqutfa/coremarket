<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\User;
use App\Services\OperationsPdfService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\InteractsWithCoreMarketTestSchema;
use Tests\TestCase;

class SupplierStatementPdfTest extends TestCase
{
    use InteractsWithCoreMarketTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('coremarket.runtime_snapshot.connection', 'mysql');
        config()->set('coremarket.features.purchasing_suppliers', true);
        $this->ensureBusinessSettingsTable();
        $this->ensurePermissionTables();
        $this->ensureLegacyUserColumns();
        $this->ensureAdminSupportTables();
    }

    public function test_statement_filters_entries_and_calculates_credit_minus_debit_balances(): void
    {
        DB::beginTransaction();
        try {
            $supplier = Supplier::query()->create(['name' => 'Statement Supplier', 'is_active' => true]);
            $this->entry($supplier, 'purchase_invoice', 'credit', 100, '2026-01-05 10:00:00');
            $this->entry($supplier, 'purchase_invoice', 'credit', 50, '2026-01-12 10:00:00');
            $this->entry($supplier, 'purchase_payment', 'debit', 20, '2026-01-15 10:00:00');
            $this->entry($supplier, 'purchase_return', 'debit', 10, '2026-01-20 10:00:00');
            $this->entry($supplier, 'purchase_invoice', 'credit', 200, '2026-02-01 10:00:00');
            $before = SupplierLedgerEntry::query()->count();

            $data = app(OperationsPdfService::class)->supplierStatement(
                $supplier,
                '2026-01-10',
                '2026-01-31'
            );

            $this->assertSame(100.0, $data['openingBalance']);
            $this->assertCount(3, $data['rows']);
            $this->assertSame([150.0, 130.0, 120.0], $data['rows']->pluck('running_balance')->all());
            $this->assertSame(50.0, $data['totals']['credits']);
            $this->assertSame(30.0, $data['totals']['debits']);
            $this->assertSame(20.0, $data['totals']['payments']);
            $this->assertSame(10.0, $data['totals']['returns']);
            $this->assertSame(120.0, $data['totals']['closingBalance']);
            $this->assertSame($before, SupplierLedgerEntry::query()->count());

            $user = $this->user(['supplier_ledger.view']);
            $this->actingAs($user)->get(route('operations.suppliers.show', $supplier))
                ->assertOk()
                ->assertSee(route('operations.suppliers.statement.pdf', $supplier), false);
            $this->actingAs($user)
                ->get(route('operations.suppliers.statement.pdf', [
                    'supplier' => $supplier,
                    'date_from' => '2026-01-10',
                    'date_to' => '2026-01-31',
                ]))
                ->assertOk()
                ->assertHeader('content-type', 'application/pdf');
            $this->assertSame($before, SupplierLedgerEntry::query()->count());
        } finally {
            DB::rollBack();
        }
    }

    public function test_supplier_without_entries_returns_a_safe_pdf(): void
    {
        DB::beginTransaction();
        try {
            $supplier = Supplier::query()->create(['name' => 'Empty Statement Supplier', 'is_active' => true]);
            $data = app(OperationsPdfService::class)->supplierStatement($supplier);

            $this->assertCount(0, $data['rows']);
            $this->assertSame(0.0, $data['openingBalance']);
            $this->assertSame(0.0, $data['totals']['closingBalance']);

            $this->actingAs($this->user(['supplier_ledger.view']))
                ->get(route('operations.suppliers.statement.pdf', $supplier))
                ->assertOk()
                ->assertHeader('content-type', 'application/pdf');
        } finally {
            DB::rollBack();
        }
    }

    private function entry(
        Supplier $supplier,
        string $entryType,
        string $direction,
        float $amount,
        string $occurredAt
    ): SupplierLedgerEntry {
        return SupplierLedgerEntry::query()->create([
            'supplier_id' => $supplier->id,
            'entry_type' => $entryType,
            'direction' => $direction,
            'amount' => $amount,
            'currency' => 'USD',
            'exchange_rate' => 1,
            'amount_usd' => $amount,
            'description' => ucwords(str_replace('_', ' ', $entryType)),
            'occurred_at' => $occurredAt,
        ]);
    }

    private function user(array $permissions): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::query()->firstOrCreate(['name' => 'Statement PDF '.uniqid(), 'guard_name' => 'web']);
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::query()->firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]));
        }
        $user = new User();
        $user->forceFill([
            'name' => 'Statement PDF QA',
            'email' => uniqid('statement-pdf').'@example.test',
            'password' => bcrypt('Temporary123!'),
            'user_type' => 'staff',
            'email_verified_at' => now(),
        ])->save();
        $user->assignRole($role);

        return $user;
    }
}
