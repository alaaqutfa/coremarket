<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\PurchaseReceipt;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\Upload;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class OperationsPdfService
{
    public function __construct(private CoreMarketMoneyService $money)
    {
    }

    public function purchaseDocument(PurchaseOrder $purchaseOrder, ?PurchaseReceipt $purchaseReceipt = null): array
    {
        $purchaseOrder->loadMissing([
            'supplier',
            'items.product',
            'items.productStock',
            'receipts.items.purchaseOrderItem',
        ]);

        if ($purchaseReceipt) {
            $purchaseReceipt->loadMissing([
                'purchaseOrder.supplier',
                'items.purchaseOrderItem.product',
                'items.purchaseOrderItem.productStock',
            ]);
            abort_unless((int) $purchaseReceipt->purchase_order_id === (int) $purchaseOrder->id, 404);
        }

        $currency = strtoupper((string) ($purchaseOrder->currency ?: $this->money->baseCurrency()));
        $rows = $purchaseReceipt
            ? $this->receiptRows($purchaseReceipt)
            : $this->orderRows($purchaseOrder);
        $totals = $purchaseReceipt
            ? $this->rowTotals($rows)
            : [
                'subtotal' => $this->money->normalizeMoney($purchaseOrder->subtotal_amount),
                'tax' => $this->money->normalizeMoney($purchaseOrder->tax_amount),
                'discount' => $this->money->normalizeMoney($purchaseOrder->discount_amount),
                'shipping' => $this->money->normalizeMoney($purchaseOrder->shipping_amount),
                'total' => $this->money->normalizeMoney($purchaseOrder->total_amount),
            ];

        return [
            'branding' => $this->branding(),
            'purchaseOrder' => $purchaseOrder,
            'purchaseReceipt' => $purchaseReceipt,
            'documentTitle' => $purchaseReceipt ? 'PURCHASE RECEIPT' : 'PURCHASE INVOICE',
            'documentNumber' => $purchaseReceipt?->receipt_key ?: $purchaseOrder->purchase_number,
            'documentDate' => ($purchaseReceipt?->received_at ?: $purchaseOrder->ordered_at ?: $purchaseOrder->created_at)?->format('Y-m-d H:i'),
            'supplierInvoiceNumber' => $this->supplierInvoiceNumber($purchaseOrder, $purchaseReceipt),
            'currency' => $currency,
            'exchangeRate' => $purchaseReceipt?->metadata['exchange_rate']
                ?? $purchaseOrder->metadata['exchange_rate']
                ?? null,
            'rows' => $rows,
            'totals' => $totals,
            'notes' => $purchaseReceipt?->notes ?: $purchaseOrder->notes,
        ];
    }

    public function supplierStatement(Supplier $supplier, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $from = $dateFrom ? CarbonImmutable::parse($dateFrom)->startOfDay() : null;
        $to = $dateTo ? CarbonImmutable::parse($dateTo)->endOfDay() : null;
        $opening = 0.0;

        if ($from) {
            $openingCredits = (float) $supplier->ledgerEntries()
                ->where('occurred_at', '<', $from)
                ->where('direction', 'credit')
                ->sum('amount_usd');
            $openingDebits = (float) $supplier->ledgerEntries()
                ->where('occurred_at', '<', $from)
                ->where('direction', 'debit')
                ->sum('amount_usd');
            $opening = $this->money->normalizeMoney($openingCredits - $openingDebits);
        }

        $entries = $supplier->ledgerEntries()
            ->when($from, fn ($query) => $query->where('occurred_at', '>=', $from))
            ->when($to, fn ($query) => $query->where('occurred_at', '<=', $to))
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $running = $opening;
        $rows = $entries->map(function (SupplierLedgerEntry $entry) use (&$running) {
            $amount = $this->money->normalizeMoney($entry->amount_usd);
            $running = $this->money->normalizeMoney(
                $running + ($entry->direction === 'credit' ? $amount : -$amount)
            );

            return [
                'date' => $entry->occurred_at?->format('Y-m-d H:i'),
                'entry_type' => $entry->entry_type,
                'reference' => $this->ledgerReference($entry),
                'description' => $entry->description,
                'debit' => $entry->direction === 'debit' ? $amount : 0.0,
                'credit' => $entry->direction === 'credit' ? $amount : 0.0,
                'running_balance' => $running,
            ];
        });

        $credits = $this->sumDirection($entries, 'credit');
        $debits = $this->sumDirection($entries, 'debit');

        return [
            'branding' => $this->branding(),
            'supplier' => $supplier,
            'dateFrom' => $from?->toDateString(),
            'dateTo' => $to?->toDateString(),
            'openingBalance' => $opening,
            'rows' => $rows,
            'totals' => [
                'credits' => $credits,
                'debits' => $debits,
                'purchases' => $this->sumEntryType($entries, 'purchase_invoice', 'credit'),
                'payments' => $this->sumEntryType($entries, 'purchase_payment', 'debit'),
                'returns' => $this->sumEntryType($entries, 'purchase_return', 'debit'),
                'closingBalance' => $this->money->normalizeMoney($opening + $credits - $debits),
            ],
        ];
    }

    private function orderRows(PurchaseOrder $purchaseOrder): Collection
    {
        return $purchaseOrder->items->map(function ($item) {
            $pricing = $item->metadata['pricing_snapshot'] ?? [];
            $tax = $item->metadata['tax_snapshot'] ?? [];
            $subtotal = $this->money->normalizeMoney($item->total_cost);
            $taxAmount = $this->money->normalizeMoney($item->tax_amount);
            $discount = $this->money->normalizeMoney($item->discount_amount);
            $storedLineTotal = $item->metadata['line_total'] ?? null;

            return $this->purchaseRow(
                $item,
                (float) $item->quantity_ordered,
                $item->unit_cost,
                $subtotal,
                $taxAmount,
                $discount,
                is_numeric($storedLineTotal)
                    ? $this->money->normalizeMoney($storedLineTotal)
                    : $this->money->normalizeMoney($subtotal + $taxAmount - $discount),
                $pricing,
                $tax
            );
        });
    }

    private function receiptRows(PurchaseReceipt $purchaseReceipt): Collection
    {
        return $purchaseReceipt->items->map(function ($receiptItem) {
            $orderItem = $receiptItem->purchaseOrderItem;
            $orderedQuantity = (float) ($orderItem?->quantity_ordered ?? 0);
            $quantity = (float) $receiptItem->quantity_received;
            $ratio = $orderedQuantity > 0 ? $quantity / $orderedQuantity : 0;
            $pricing = $orderItem?->metadata['pricing_snapshot'] ?? [];
            $tax = $orderItem?->metadata['tax_snapshot'] ?? [];
            $subtotal = $this->money->normalizeMoney($receiptItem->total_cost);
            $taxAmount = $this->money->normalizeMoney((float) ($orderItem?->tax_amount ?? 0) * $ratio);
            $discount = $this->money->normalizeMoney((float) ($orderItem?->discount_amount ?? 0) * $ratio);

            return $this->purchaseRow(
                $orderItem,
                $quantity,
                $receiptItem->unit_cost,
                $subtotal,
                $taxAmount,
                $discount,
                $this->money->normalizeMoney($subtotal + $taxAmount - $discount),
                $pricing,
                $tax
            );
        });
    }

    private function purchaseRow(
        ?object $item,
        float $quantity,
        mixed $unitCost,
        float $subtotal,
        float $taxAmount,
        float $discount,
        float $lineTotal,
        array $pricing,
        array $tax
    ): array {
        return [
            'product_name' => $item?->product?->name ?: '#'.($item?->product_id ?? ''),
            'variant' => $item?->productStock?->variant ?: ($item?->variant ?? null),
            'sku' => $item?->productStock?->sku,
            'barcode' => $item?->productStock?->barcode ?: $item?->product?->barcode,
            'quantity' => $quantity,
            'unit_cost' => $this->money->normalizeMoney($unitCost),
            'regular_price' => is_numeric($pricing['regular_price'] ?? null)
                ? $this->money->normalizeMoney($pricing['regular_price'])
                : null,
            'sale_price' => is_numeric($pricing['sale_price'] ?? null)
                ? $this->money->normalizeMoney($pricing['sale_price'])
                : null,
            'tax_rate' => is_numeric($tax['rate'] ?? null) ? (float) $tax['rate'] : null,
            'tax_amount' => $taxAmount,
            'discount' => $discount,
            'subtotal' => $subtotal,
            'line_total' => $lineTotal,
        ];
    }

    private function rowTotals(Collection $rows): array
    {
        $subtotal = $this->money->normalizeMoney($rows->sum('subtotal'));
        $tax = $this->money->normalizeMoney($rows->sum('tax_amount'));
        $discount = $this->money->normalizeMoney($rows->sum('discount'));

        return [
            'subtotal' => $subtotal,
            'tax' => $tax,
            'discount' => $discount,
            'shipping' => 0.0,
            'total' => $this->money->normalizeMoney($subtotal + $tax - $discount),
        ];
    }

    private function branding(): array
    {
        $color = (string) get_setting('base_color', '#2563EB');
        if (! preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $color = '#2563EB';
        }

        return [
            'store_name' => coremarketStoreName(),
            'address' => (string) get_setting('contact_address'),
            'email' => (string) get_setting('contact_email'),
            'phone' => (string) get_setting('contact_phone'),
            'color' => $color,
            'logo_path' => $this->safeLogoPath(),
        ];
    }

    private function safeLogoPath(): ?string
    {
        $logoId = get_setting('header_logo');
        if (! is_numeric($logoId)) {
            return null;
        }

        $upload = Upload::query()->find((int) $logoId);
        if (! $upload?->file_name) {
            return null;
        }

        $path = public_path($upload->file_name);
        if (! is_file($path)) {
            return null;
        }

        return 'file:///'.str_replace('\\', '/', $path);
    }

    private function supplierInvoiceNumber(PurchaseOrder $purchaseOrder, ?PurchaseReceipt $purchaseReceipt): ?string
    {
        foreach ([
            $purchaseReceipt?->metadata['supplier_invoice_number'] ?? null,
            $purchaseReceipt?->metadata['invoice_number'] ?? null,
            $purchaseOrder->metadata['supplier_invoice_number'] ?? null,
            $purchaseOrder->metadata['invoice_number'] ?? null,
        ] as $value) {
            if (filled($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    private function ledgerReference(SupplierLedgerEntry $entry): string
    {
        if (! $entry->reference_type || ! $entry->reference_id) {
            return '-';
        }

        return class_basename($entry->reference_type).' #'.$entry->reference_id;
    }

    private function sumDirection(Collection $entries, string $direction): float
    {
        return $this->money->normalizeMoney(
            $entries->where('direction', $direction)->sum('amount_usd')
        );
    }

    private function sumEntryType(Collection $entries, string $entryType, string $direction): float
    {
        return $this->money->normalizeMoney(
            $entries->where('entry_type', $entryType)->where('direction', $direction)->sum('amount_usd')
        );
    }
}
