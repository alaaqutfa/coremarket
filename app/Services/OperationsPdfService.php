<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\PurchaseReceipt;
use App\Models\Order;
use App\Models\SalesReturn;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\Upload;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class OperationsPdfService
{
    public function __construct(
        private CoreMarketMoneyService $money,
        private CoreMarketDocumentTemplateService $templates,
        private CoreMarketCustomerReceivableService $receivables
    )
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

        $template = $this->templates->templateSettingsSnapshot(
            $this->templates->defaultTemplate($purchaseReceipt ? 'purchase_receipt' : 'purchase_order')
        );

        return [
            'branding' => $this->branding($template),
            'template' => $template,
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

        $template = $this->templates->templateSettingsSnapshot(
            $this->templates->defaultTemplate('supplier_statement')
        );

        return [
            'branding' => $this->branding($template),
            'template' => $template,
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

    public function salesInvoice(Order $order): array
    {
        $order->loadMissing([
            'user',
            'orderDetails.product.stocks',
            'delivery.deliveryUser',
        ]);

        $rows = $this->salesRows($order);
        $subtotal = $this->money->normalizeMoney($order->orderDetails->sum('price'));
        $tax = $this->money->normalizeMoney($order->orderDetails->sum('tax'));
        $shipping = $this->money->normalizeMoney($order->orderDetails->sum('shipping_cost'));
        $discount = $this->money->normalizeMoney($order->coupon_discount);
        $total = $this->money->normalizeMoney($order->grand_total);
        $paid = $this->paidAmount($order);
        $template = $this->templates->templateSettingsSnapshot(
            $this->templates->defaultTemplate('sales_invoice')
        );

        return [
            'branding' => $this->branding($template),
            'template' => $template,
            'order' => $order,
            'customer' => $this->customerSnapshot($order),
            'documentTitle' => 'SALES INVOICE',
            'documentNumber' => $order->code ?: '#'.$order->id,
            'documentDate' => $this->orderDate($order)?->format('Y-m-d H:i'),
            'currency' => $this->money->baseCurrency(),
            'rows' => $rows,
            'totals' => [
                'subtotal' => $subtotal,
                'tax' => $tax,
                'discount' => $discount,
                'shipping' => $shipping,
                'total' => $total,
                'paid' => $paid,
                'outstanding' => max(0, $this->money->normalizeMoney($total - $paid)),
            ],
        ];
    }

    public function customerStatement(User $customer, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        if ($this->receivables->enabled()) {
            $template = $this->templates->templateSettingsSnapshot(
                $this->templates->defaultTemplate('customer_statement')
            );

            return array_merge(
                [
                    'branding' => $this->branding($template),
                    'template' => $template,
                ],
                $this->receivables->statementSnapshot($customer, $dateFrom, $dateTo)
            );
        }

        $from = $dateFrom ? CarbonImmutable::parse($dateFrom)->startOfDay() : null;
        $to = $dateTo ? CarbonImmutable::parse($dateTo)->endOfDay() : null;
        $opening = 0.0;

        if ($from) {
            $openingOrders = Order::query()
                ->where('user_id', $customer->id)
                ->where('created_at', '<', $from)
                ->get();
            $opening = $this->money->normalizeMoney(
                $openingOrders->sum(fn (Order $order) => (float) $order->grand_total - $this->paidAmount($order))
                - $this->completedReturnTotal($customer, null, $from->subMicrosecond())
            );
        }

        $orders = Order::query()
            ->where('user_id', $customer->id)
            ->when($from, fn ($query) => $query->where('created_at', '>=', $from))
            ->when($to, fn ($query) => $query->where('created_at', '<=', $to))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        $returns = $this->completedReturns($customer, $from, $to);

        $events = collect();
        foreach ($orders as $order) {
            $events->push([
                'sort_at' => $order->created_at,
                'date' => $this->orderDate($order)?->format('Y-m-d H:i'),
                'entry_type' => 'order',
                'reference' => $order->code ?: '#'.$order->id,
                'description' => 'Order - '.ucwords(str_replace('_', ' ', (string) $order->payment_status)),
                'debit' => $this->money->normalizeMoney($order->grand_total),
                'credit' => $this->paidAmount($order),
            ]);
        }
        foreach ($returns as $return) {
            $events->push([
                'sort_at' => $return->completed_at ?: $return->updated_at,
                'date' => ($return->completed_at ?: $return->updated_at)?->format('Y-m-d H:i'),
                'entry_type' => 'sales_return',
                'reference' => $return->return_number ?: '#'.$return->id,
                'description' => 'Completed sales return',
                'debit' => 0.0,
                'credit' => $this->money->normalizeMoney($return->total_amount),
            ]);
        }

        $running = $opening;
        $rows = $events->sortBy('sort_at')->values()->map(function (array $event) use (&$running) {
            $running = $this->money->normalizeMoney($running + $event['debit'] - $event['credit']);
            unset($event['sort_at']);
            $event['running_balance'] = $running;

            return $event;
        });

        $template = $this->templates->templateSettingsSnapshot(
            $this->templates->defaultTemplate('customer_statement')
        );
        $charges = $this->money->normalizeMoney($rows->sum('debit'));
        $credits = $this->money->normalizeMoney($rows->sum('credit'));

        return [
            'branding' => $this->branding($template),
            'template' => $template,
            'customer' => $customer,
            'dateFrom' => $from?->toDateString(),
            'dateTo' => $to?->toDateString(),
            'openingBalance' => $opening,
            'rows' => $rows,
            'totals' => [
                'charges' => $charges,
                'credits' => $credits,
                'closingBalance' => $this->money->normalizeMoney($opening + $charges - $credits),
            ],
            'isOperationalStatement' => true,
        ];
    }

    public function deliveryDocument(Order $order, string $type = 'delivery_note'): array
    {
        if (! in_array($type, ['delivery_note', 'packing_slip'], true)) {
            throw new DomainException('Unsupported delivery document type.');
        }

        $order->loadMissing([
            'user',
            'orderDetails.product.stocks',
            'delivery.deliveryUser',
        ]);
        $template = $this->templates->templateSettingsSnapshot(
            $this->templates->defaultTemplate($type)
        );

        return [
            'branding' => $this->branding($template),
            'template' => $template,
            'order' => $order,
            'customer' => $this->customerSnapshot($order),
            'documentTitle' => $type === 'packing_slip' ? 'PACKING SLIP' : 'DELIVERY NOTE',
            'documentNumber' => $order->code ?: '#'.$order->id,
            'documentDate' => $this->orderDate($order)?->format('Y-m-d H:i'),
            'rows' => $this->salesRows($order)->map(fn (array $row) => [
                'product_name' => $row['product_name'],
                'variant' => $row['variant'],
                'sku' => $row['sku'],
                'barcode' => $row['barcode'],
                'quantity' => $row['quantity'],
            ]),
            'delivery' => $order->delivery,
        ];
    }

    private function salesRows(Order $order): Collection
    {
        return $order->orderDetails->map(function ($detail) {
            $quantity = (float) $detail->quantity;
            $stock = $detail->product?->stocks?->firstWhere('variant', $detail->variation)
                ?? $detail->product?->stocks?->first();
            $linePrice = $this->money->normalizeMoney($detail->price);
            $tax = $this->money->normalizeMoney($detail->tax);

            return [
                'product_name' => $detail->product?->name ?: 'Product unavailable',
                'variant' => $detail->variation,
                'sku' => $stock?->sku,
                'barcode' => $stock?->barcode ?: $detail->product?->barcode,
                'quantity' => $quantity,
                'unit_price' => $quantity > 0
                    ? $this->money->normalizeMoney($linePrice / $quantity)
                    : 0.0,
                'tax_amount' => $tax,
                'discount' => 0.0,
                'line_total' => $this->money->normalizeMoney($linePrice + $tax),
            ];
        });
    }

    private function customerSnapshot(Order $order): array
    {
        $shipping = json_decode((string) $order->shipping_address, true);
        if (! is_array($shipping)) {
            $shipping = [];
        }

        return [
            'id' => $order->user_id,
            'name' => $order->user?->name ?: ($shipping['name'] ?? 'Walk-in customer'),
            'email' => $order->user?->email ?: ($shipping['email'] ?? null),
            'phone' => $order->user?->phone ?: ($shipping['phone'] ?? null),
            'address' => $shipping['address'] ?? $order->user?->address,
            'city' => $shipping['city'] ?? $order->user?->city,
            'country' => $shipping['country'] ?? $order->user?->country,
        ];
    }

    private function paidAmount(Order $order): float
    {
        if ($order->payment_status === 'paid') {
            return $this->money->normalizeMoney($order->grand_total);
        }

        return max(0, min(
            $this->money->normalizeMoney($order->grand_total),
            $this->money->normalizeMoney($order->paid_amount ?? 0)
        ));
    }

    private function completedReturns(User $customer, ?CarbonImmutable $from, ?CarbonImmutable $to): Collection
    {
        if (! Schema::hasTable('sales_returns')) {
            return collect();
        }

        return SalesReturn::query()
            ->where('status', 'completed')
            ->whereHas('order', fn ($query) => $query->where('user_id', $customer->id))
            ->when($from, fn ($query) => $query->where('completed_at', '>=', $from))
            ->when($to, fn ($query) => $query->where('completed_at', '<=', $to))
            ->orderBy('completed_at')
            ->orderBy('id')
            ->get();
    }

    private function completedReturnTotal(User $customer, ?CarbonImmutable $from, ?CarbonImmutable $to): float
    {
        return $this->money->normalizeMoney(
            $this->completedReturns($customer, $from, $to)->sum('total_amount')
        );
    }

    private function orderDate(Order $order): ?CarbonImmutable
    {
        if (is_numeric($order->date) && (int) $order->date > 0) {
            return CarbonImmutable::createFromTimestamp((int) $order->date);
        }

        return $order->created_at ? CarbonImmutable::instance($order->created_at) : null;
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

    private function branding(array $template): array
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
            'color' => $template['settings']['primary_color'] ?? $color,
            'secondary_color' => $template['settings']['secondary_color'] ?? '#64748B',
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
