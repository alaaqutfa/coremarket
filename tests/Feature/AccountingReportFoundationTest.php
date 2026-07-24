<?php

namespace Tests\Feature;

use App\Models\AccountingEvent;
use App\Models\CashierShift;
use App\Models\CashMovement;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\TaxSnapshot;
use App\Models\User;
use App\Services\CoreMarketAccountingReportService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountingReportFoundationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_report_calculates_filtered_operational_summaries_without_writes(): void
    {
        $beforeLedger = SupplierLedgerEntry::query()->count();
        $beforeInventoryValue = app(CoreMarketAccountingReportService::class)
            ->report()['inventory']['estimated_stock_value'];
        $supplier = Supplier::query()->create(['name' => 'Report Supplier', 'is_active' => true]);
        $this->ledger($supplier, 'purchase_invoice', 'credit', 100, '2026-04-02 10:00:00');
        $this->ledger($supplier, 'purchase_payment', 'debit', 25, '2026-04-03 10:00:00');
        $this->ledger($supplier, 'purchase_return', 'debit', 10, '2026-04-04 10:00:00');
        $this->ledger($supplier, 'purchase_invoice', 'credit', 500, '2026-05-01 10:00:00');

        $this->event('sale', 200, 120, 20, '2026-04-02 10:00:00');
        $this->event('sale_return', 20, 12, 2, '2026-04-03 10:00:00');
        $this->event('expense', 30, null, 0, '2026-04-04 10:00:00');
        $this->event('purchase_receipt', 100, 100, 11, '2026-04-05 10:00:00');
        $this->event('sale', 999, 100, 0, '2026-05-01 10:00:00');
        TaxSnapshot::query()->create([
            'source_type' => AccountingEvent::class,
            'source_id' => 999999,
            'tax_amount' => 20,
            'currency' => 'USD',
            'created_at' => '2026-04-02 10:00:00',
            'updated_at' => '2026-04-02 10:00:00',
        ]);

        $shift = CashierShift::query()->create([
            'cashbox_id' => 1,
            'opened_by' => 1,
            'status' => 'open',
            'opened_at' => '2026-04-02 09:00:00',
            'opening_balance' => 0,
        ]);
        $this->cash($shift, 'in', 200, '2026-04-02 10:00:00');
        $this->cash($shift, 'out', 30, '2026-04-02 11:00:00');

        [$productId] = $this->productWithStock(3, 8);
        $report = app(CoreMarketAccountingReportService::class)->report([
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-30',
        ]);

        $this->assertSame(200.0, $report['profit']['sales_total']);
        $this->assertSame(20.0, $report['profit']['sales_returns_total']);
        $this->assertSame(180.0, $report['profit']['net_sales']);
        $this->assertSame(108.0, $report['profit']['net_cogs']);
        $this->assertSame(72.0, $report['profit']['gross_profit']);
        $this->assertSame(42.0, $report['profit']['estimated_net_profit']);
        $this->assertSame($beforeInventoryValue + 24.0, $report['inventory']['estimated_stock_value']);
        $this->assertSame(65.0, $report['suppliers']['balance']);
        $this->assertSame(7.0, $report['tax']['net_tax_estimate']);
        $this->assertSame(170.0, $report['cashbox']['expected_cash_movement']);
        $this->assertSame(100.0, $report['purchases']['purchases_total']);
        $this->assertSame(25.0, $report['purchases']['supplier_payments_total']);
        $this->assertSame(10.0, $report['purchases']['purchase_returns_total']);
        $this->assertSame(65.0, $report['purchases']['outstanding_supplier_balance']);
        $this->assertSame($beforeLedger + 4, SupplierLedgerEntry::query()->count());

        DB::table('product_stocks')->where('product_id', $productId)->delete();
        DB::table('products')->where('id', $productId)->delete();
    }

    public function test_empty_report_and_authorized_page_are_safe_and_money_is_normalized(): void
    {
        $report = app(CoreMarketAccountingReportService::class)->report([
            'date_from' => '2035-01-01',
            'date_to' => '2035-01-31',
        ]);

        $this->assertSame(0.0, $report['profit']['net_sales']);
        $this->assertSame(0.0, $report['tax']['net_tax_estimate']);
        $this->assertSame(0, $report['cashbox']['shift_count']);

        $user = new User();
        $user->forceFill([
            'name' => 'Accounting Report Admin',
            'email' => 'accounting-report-'.uniqid().'@example.test',
            'password' => bcrypt('Temporary123!'),
            'user_type' => 'admin',
            'email_verified_at' => now(),
        ])->save();

        $this->actingAs($user)
            ->get(route('operations.accounting.reports', [
                'date_from' => '2035-01-01',
                'date_to' => '2035-01-31',
            ]))
            ->assertOk()
            ->assertSee('Accounting &amp; Operations Reports', false)
            ->assertSee('0.00 USD', false)
            ->assertSee('not an official VAT filing');
    }

    private function event(
        string $type,
        float $amount,
        ?float $cost,
        float $tax,
        string $occurredAt
    ): void {
        AccountingEvent::query()->create([
            'event_type' => $type,
            'direction' => $type === 'sale' ? 'income' : 'expense',
            'reference_type' => 'Tests\\AccountingReport\\'.$type,
            'reference_id' => random_int(100000, 999999),
            'amount' => $amount,
            'cost_amount' => $cost,
            'tax_amount' => $tax,
            'profit_amount' => $cost === null ? null : $amount - $cost,
            'occurred_at' => $occurredAt,
            'status' => 'posted',
        ]);
    }

    private function ledger(
        Supplier $supplier,
        string $type,
        string $direction,
        float $amount,
        string $occurredAt
    ): void {
        SupplierLedgerEntry::query()->create([
            'supplier_id' => $supplier->id,
            'entry_type' => $type,
            'direction' => $direction,
            'amount' => $amount,
            'currency' => 'USD',
            'exchange_rate' => 1,
            'amount_usd' => $amount,
            'occurred_at' => $occurredAt,
        ]);
    }

    private function cash(CashierShift $shift, string $direction, float $amount, string $occurredAt): void
    {
        CashMovement::query()->create([
            'cashbox_id' => $shift->cashbox_id,
            'cashier_shift_id' => $shift->id,
            'movement_type' => $direction === 'in' ? 'sale' : 'cash_out',
            'direction' => $direction,
            'amount' => $amount,
            'currency' => 'USD',
            'occurred_at' => $occurredAt,
        ]);
    }

    private function productWithStock(float $quantity, float $cost): array
    {
        $now = now();
        $productId = DB::table('products')->insertGetId([
            'name' => 'Accounting Report Product',
            'user_id' => 1,
            'category_id' => 1,
            'unit_price' => 15,
            'purchase_price' => $cost,
            'current_stock' => $quantity,
            'low_stock_quantity' => 1,
            'slug' => 'accounting-report-'.uniqid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $stockId = DB::table('product_stocks')->insertGetId([
            'product_id' => $productId,
            'variant' => '',
            'sku' => 'REPORT-'.uniqid(),
            'price' => 15,
            'qty' => $quantity,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$productId, $stockId];
    }
}
