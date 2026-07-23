<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SupplierPaymentService
{
    public function __construct(
        private CoreMarketMoneyService $money,
        private SupplierLedgerService $ledger
    ) {
    }

    public function createPayment(Supplier $supplier, array $attributes, ?int $createdBy = null): SupplierPayment
    {
        $paymentKey = trim((string) ($attributes['payment_key'] ?? ''));
        if ($paymentKey === '') {
            throw new InvalidArgumentException('Supplier payment requires a payment key.');
        }

        return DB::transaction(function () use ($supplier, $attributes, $createdBy, $paymentKey) {
            $existing = SupplierPayment::query()->where('payment_key', $paymentKey)->first();
            if ($existing) {
                if ((int) $existing->supplier_id !== (int) $supplier->id) {
                    throw new DomainException('Payment key belongs to another supplier.');
                }
                $requestedAmount = $this->positiveMoney($attributes['amount'] ?? null);
                $requestedOrderId = $attributes['purchase_order_id'] ?? null;
                if (
                    (float) $existing->amount !== $requestedAmount
                    || (string) ($existing->purchase_order_id ?? '') !== (string) ($requestedOrderId ?? '')
                ) {
                    throw new DomainException('Payment key was already used with different payment details.');
                }

                return $existing;
            }

            $supplier = Supplier::query()->lockForUpdate()->findOrFail($supplier->id);
            $amount = $this->positiveMoney($attributes['amount'] ?? null);
            $exchangeRate = $this->positiveRate($attributes['exchange_rate'] ?? 1);
            $purchaseOrderId = $attributes['purchase_order_id'] ?? null;
            if ($purchaseOrderId) {
                $matchesSupplier = PurchaseOrder::query()
                    ->whereKey($purchaseOrderId)
                    ->where('supplier_id', $supplier->id)
                    ->exists();
                if (! $matchesSupplier) {
                    throw new DomainException('Purchase order does not belong to this supplier.');
                }
            }

            $payment = $supplier->payments()->create([
                'payment_key' => $paymentKey,
                'purchase_order_id' => $purchaseOrderId,
                'amount' => $amount,
                'currency' => strtoupper((string) ($attributes['currency'] ?? $this->money->baseCurrency())),
                'exchange_rate' => $exchangeRate,
                'amount_usd' => $this->money->convertByRates($amount, $exchangeRate, 1),
                'payment_method' => $attributes['payment_method'] ?? null,
                'payment_reference' => $attributes['payment_reference'] ?? null,
                'paid_at' => $attributes['paid_at'] ?? now(),
                'notes' => $attributes['notes'] ?? null,
                'metadata' => $attributes['metadata'] ?? null,
                'created_by' => $createdBy,
            ]);

            $this->ledger->recordPayment($payment->load('supplier'), $createdBy);

            return $payment->fresh();
        });
    }

    private function positiveMoney(mixed $amount): float
    {
        $value = $this->money->normalizeMoney($amount);
        if ($value <= 0) {
            throw new InvalidArgumentException('Payment amount must be greater than zero.');
        }

        return $value;
    }

    private function positiveRate(mixed $rate): float
    {
        if (! is_numeric($rate) || (float) $rate <= 0) {
            throw new InvalidArgumentException('Exchange rate must be greater than zero.');
        }

        return (float) $rate;
    }
}
