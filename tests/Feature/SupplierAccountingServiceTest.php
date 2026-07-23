<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Services\PurchaseReceivingService;
use App\Services\SupplierLedgerService;
use App\Services\SupplierPaymentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplierAccountingServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_received_purchase_increases_balance_and_payment_decreases_it_once(): void
    {
        [$supplier, $productId, $stockId] = $this->fixtures();
        $receiving = app(PurchaseReceivingService::class);
        $order = $receiving->createPurchaseOrder([
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'status' => 'ordered',
        ], [[
            'product_id' => $productId,
            'product_stock_id' => $stockId,
            'quantity_ordered' => 5,
            'unit_cost' => 8,
            'tax_amount' => 2,
        ]]);
        $receiptKey = 'supplier-ledger-receipt-'.uniqid();
        $receiving->receive($order, [[
            'purchase_order_item_id' => $order->items->first()->id,
            'quantity_received' => 5,
        ]], ['receipt_key' => $receiptKey]);
        $receiving->receive($order, [[
            'purchase_order_item_id' => $order->items->first()->id,
            'quantity_received' => 5,
        ]], ['receipt_key' => $receiptKey]);

        $ledger = app(SupplierLedgerService::class);
        $this->assertSame(42.0, $ledger->supplierBalance($supplier));
        $this->assertDatabaseHas('supplier_ledger_entries', [
            'supplier_id' => $supplier->id,
            'entry_type' => 'purchase_invoice',
            'direction' => 'credit',
            'amount_usd' => '42.000000',
        ]);
        $this->assertSame(1, DB::table('supplier_ledger_entries')->where('entry_type', 'purchase_invoice')->count());

        $key = 'supplier-payment-'.uniqid();
        $payments = app(SupplierPaymentService::class);
        $payment = $payments->createPayment($supplier, [
            'payment_key' => $key,
            'purchase_order_id' => $order->id,
            'amount' => 10.126,
            'currency' => 'USD',
            'exchange_rate' => 1,
            'payment_method' => 'bank_transfer',
        ]);
        $again = $payments->createPayment($supplier, [
            'payment_key' => $key,
            'purchase_order_id' => $order->id,
            'amount' => 10.13,
        ]);

        $this->assertSame($payment->id, $again->id);
        $this->assertSame(31.87, $ledger->supplierBalance($supplier));
        $this->assertSame('10.13 USD', coremarket_money($payment->amount, $payment->currency));
        $this->assertSame(1, DB::table('supplier_payments')->where('payment_key', $key)->count());
        $this->assertSame(1, DB::table('supplier_ledger_entries')->where('reference_type', $payment::class)->where('reference_id', $payment->id)->count());
    }

    private function fixtures(): array
    {
        $now = now();
        $supplier = Supplier::query()->create(['name' => 'Supplier Accounting '.uniqid(), 'is_active' => true]);
        $productId = DB::table('products')->insertGetId([
            'name' => 'Supplier Accounting Product',
            'user_id' => 1,
            'category_id' => 1,
            'unit_price' => 20,
            'purchase_price' => 8,
            'current_stock' => 2,
            'slug' => 'supplier-accounting-'.uniqid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $stockId = DB::table('product_stocks')->insertGetId([
            'product_id' => $productId,
            'variant' => '',
            'sku' => 'SUP-'.uniqid(),
            'price' => 20,
            'qty' => 2,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$supplier, $productId, $stockId];
    }
}
