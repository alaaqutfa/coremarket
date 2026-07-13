<?php

namespace Tests\Feature;

use App\Models\AccountingEvent;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\OrderDetail;
use App\Models\ProductStock;
use App\Services\AccountingEventService;
use App\Services\AccountingSummaryService;
use App\Services\InventoryMovementService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountingLiteFoundationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_sale_event_uses_order_detail_snapshots_once(): void
    {
        [$detail, $stock] = $this->detail();
        app(InventoryMovementService::class)->recordSale($detail, $stock);
        app(InventoryMovementService::class)->recordSale($detail->fresh(), $stock);

        $event = AccountingEvent::where('event_type', 'sale')->first();
        $this->assertSame('50.000000', $event->amount);
        $this->assertSame('20.000000', $event->cost_amount);
        $this->assertSame('5.000000', $event->tax_amount);
        $this->assertSame('30.000000', $event->profit_amount);
        $this->assertDatabaseCount('accounting_events', 1);
    }

    public function test_expense_approval_records_one_event_and_summary_reports_unknown_cost(): void
    {
        $category = ExpenseCategory::create(['name' => 'Operations']);
        $expense = Expense::create(['expense_category_id' => $category->id, 'title' => 'Rent', 'amount' => 12, 'status' => 'draft']);
        app(AccountingEventService::class)->approveExpense($expense, 1);

        $this->assertDatabaseHas('accounting_events', ['event_type' => 'expense', 'reference_id' => $expense->id, 'amount' => '12.000000']);
        AccountingEvent::create(['event_type' => 'sale', 'direction' => 'income', 'reference_type' => 'manual', 'reference_id' => 999, 'amount' => 100, 'cost_amount' => 40, 'profit_amount' => 60, 'status' => 'posted']);
        AccountingEvent::create(['event_type' => 'sale_return', 'direction' => 'expense', 'reference_type' => 'manual_return', 'reference_id' => 999, 'amount' => 20, 'cost_amount' => 8, 'profit_amount' => 12, 'status' => 'posted']);
        AccountingEvent::create(['event_type' => 'sale', 'direction' => 'income', 'reference_type' => 'unknown', 'reference_id' => 999, 'amount' => 10, 'status' => 'posted']);

        $summary = app(AccountingSummaryService::class)->summary();
        $this->assertSame(36.0, $summary['net_lite_profit']);
        $this->assertSame(1, $summary['unknown_cost_events']);
    }

    private function detail(): array
    {
        $now = now();
        $product = DB::table('products')->insertGetId(['name' => 'Accounting Product', 'user_id' => 1, 'category_id' => 1, 'unit_price' => 25, 'purchase_price' => 10, 'current_stock' => 2, 'slug' => 'accounting-' . uniqid(), 'created_at' => $now, 'updated_at' => $now]);
        $stock = DB::table('product_stocks')->insertGetId(['product_id' => $product, 'variant' => '', 'sku' => 'AC-' . uniqid(), 'price' => 25, 'qty' => 2, 'created_at' => $now, 'updated_at' => $now]);
        $order = DB::table('orders')->insertGetId(['shipping_type' => 'home_delivery', 'date' => $now->timestamp, 'created_at' => $now, 'updated_at' => $now]);
        $detail = DB::table('order_details')->insertGetId(['order_id' => $order, 'product_id' => $product, 'variation' => '', 'price' => 50, 'tax' => 5, 'quantity' => 2, 'cost_price' => 10, 'cost_source' => 'product_purchase_price', 'total_cost' => 20, 'profit_amount' => 30, 'created_at' => $now, 'updated_at' => $now]);

        return [OrderDetail::findOrFail($detail), ProductStock::findOrFail($stock)];
    }
}
