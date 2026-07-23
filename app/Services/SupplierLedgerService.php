<?php

namespace App\Services;

use App\Models\PurchaseReceipt;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\SupplierPayment;

class SupplierLedgerService
{
    public function __construct(private CoreMarketMoneyService $money)
    {
    }

    public function recordPurchase(PurchaseReceipt $receipt, ?int $createdBy = null): ?SupplierLedgerEntry
    {
        $receipt->loadMissing(['purchaseOrder.supplier', 'items.purchaseOrderItem']);
        $order = $receipt->purchaseOrder;
        if (! $order?->supplier) {
            return null;
        }

        $amount = $receipt->items->sum(function ($receiptItem) {
            $orderItem = $receiptItem->purchaseOrderItem;
            $ordered = (float) ($orderItem?->quantity_ordered ?? 0);
            $ratio = $ordered > 0 ? (float) $receiptItem->quantity_received / $ordered : 0;
            $tax = (float) ($orderItem?->tax_amount ?? 0) * $ratio;
            $discount = (float) ($orderItem?->discount_amount ?? 0) * $ratio;

            return (float) $receiptItem->total_cost + $tax - $discount;
        });
        $currency = strtoupper((string) ($order->currency ?: $this->money->baseCurrency()));
        $exchangeRate = $this->exchangeRate($order->metadata['exchange_rate'] ?? 1);

        return $this->record(
            $order->supplier,
            $receipt,
            'purchase_invoice',
            'credit',
            $amount,
            $currency,
            $exchangeRate,
            'Purchase receipt '.$receipt->receipt_key,
            $receipt->received_at,
            ['purchase_order_id' => $order->id, 'receipt_key' => $receipt->receipt_key],
            $createdBy
        );
    }

    public function recordPayment(SupplierPayment $payment, ?int $createdBy = null): SupplierLedgerEntry
    {
        return $this->record(
            $payment->supplier,
            $payment,
            'purchase_payment',
            'debit',
            $payment->amount,
            $payment->currency,
            $payment->exchange_rate,
            'Supplier payment '.$payment->payment_key,
            $payment->paid_at,
            ['purchase_order_id' => $payment->purchase_order_id, 'payment_method' => $payment->payment_method],
            $createdBy ?? $payment->created_by
        );
    }

    public function recordPurchaseReturn(PurchaseReturn $purchaseReturn, ?int $createdBy = null): SupplierLedgerEntry
    {
        return $this->record(
            $purchaseReturn->supplier,
            $purchaseReturn,
            'purchase_return',
            'debit',
            $purchaseReturn->total,
            $purchaseReturn->currency,
            $purchaseReturn->exchange_rate,
            'Purchase return '.$purchaseReturn->return_number,
            $purchaseReturn->completed_at ?? now(),
            ['purchase_order_id' => $purchaseReturn->purchase_order_id],
            $createdBy ?? $purchaseReturn->completed_by
        );
    }

    public function supplierBalance(Supplier $supplier): float
    {
        $credits = (float) $supplier->ledgerEntries()->where('direction', 'credit')->sum('amount_usd');
        $debits = (float) $supplier->ledgerEntries()->where('direction', 'debit')->sum('amount_usd');

        return $this->money->normalizeMoney($credits - $debits);
    }

    private function record(
        Supplier $supplier,
        object $reference,
        string $entryType,
        string $direction,
        mixed $amount,
        string $currency,
        mixed $exchangeRate,
        string $description,
        mixed $occurredAt,
        array $metadata,
        ?int $createdBy
    ): SupplierLedgerEntry {
        $normalizedAmount = $this->money->normalizeMoney($amount);
        $rate = $this->exchangeRate($exchangeRate);

        return SupplierLedgerEntry::query()->firstOrCreate(
            [
                'reference_type' => $reference::class,
                'reference_id' => $reference->id,
                'entry_type' => $entryType,
            ],
            [
                'supplier_id' => $supplier->id,
                'direction' => $direction,
                'amount' => $normalizedAmount,
                'currency' => strtoupper($currency),
                'exchange_rate' => $rate,
                'amount_usd' => $this->money->convertByRates($normalizedAmount, $rate, 1),
                'description' => $description,
                'metadata' => $metadata,
                'occurred_at' => $occurredAt,
                'created_by' => $createdBy,
            ]
        );
    }

    private function exchangeRate(mixed $rate): float
    {
        $value = is_numeric($rate) ? (float) $rate : 0;
        if ($value <= 0) {
            throw new \InvalidArgumentException('Exchange rate must be greater than zero.');
        }

        return $value;
    }
}
