<?php

namespace App\Services;

use App\Models\AccountingEvent;
use App\Models\CashierShift;
use App\Models\CashMovement;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\TaxSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CoreMarketAccountingReportService
{
    public function __construct(
        private CoreMarketMoneyService $money,
        private InventoryProService $inventory
    ) {
    }

    public function report(array $filters = []): array
    {
        [$from, $to] = $this->dateRange($filters);

        return [
            'filters' => [
                'date_from' => $from?->toDateString(),
                'date_to' => $to?->toDateString(),
            ],
            'profit' => $this->profitSummary($from, $to),
            'inventory' => $this->inventoryValuation(),
            'suppliers' => $this->supplierBalances($from, $to),
            'tax' => $this->taxSummary($from, $to),
            'cashbox' => $this->cashboxSummary($from, $to),
            'purchases' => $this->purchaseSummary($from, $to),
        ];
    }

    private function profitSummary(?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $events = $this->eventQuery($from, $to);
        $sum = fn (string $type, string $column): float => $this->amount(
            (clone $events)->where('event_type', $type)->sum($column)
        );

        $sales = $sum('sale', 'amount');
        $returns = $sum('sale_return', 'amount');
        $netSales = $this->amount($sales - $returns);
        $salesCogs = $sum('sale', 'cost_amount');
        $returnedCogs = $sum('sale_return', 'cost_amount');
        $netCogs = $this->amount($salesCogs - $returnedCogs);
        $grossProfit = $this->amount($netSales - $netCogs);
        $expenses = $sum('expense', 'amount');

        return [
            'sales_total' => $sales,
            'sales_returns_total' => $returns,
            'net_sales' => $netSales,
            'sales_cogs' => $salesCogs,
            'returned_cogs' => $returnedCogs,
            'net_cogs' => $netCogs,
            'gross_profit' => $grossProfit,
            'expenses_total' => $expenses,
            'estimated_net_profit' => $this->amount($grossProfit - $expenses),
            'unknown_cost_events' => (clone $events)
                ->whereIn('event_type', ['sale', 'sale_return'])
                ->whereNull('cost_amount')
                ->count(),
        ];
    }

    private function inventoryValuation(): array
    {
        $stocks = ProductStock::query()->with('product:id,purchase_price,low_stock_quantity')->get();
        $stockQuantity = (float) $stocks->sum(fn (ProductStock $stock) => (float) $stock->qty);
        $estimatedValue = $stocks->sum(
            fn (ProductStock $stock) => (float) $stock->qty * (float) ($stock->product?->purchase_price ?? 0)
        );

        return [
            'products_count' => Product::query()->count(),
            'variants_count' => $stocks->count(),
            'stock_quantity' => $stockQuantity,
            'estimated_stock_value' => $this->amount($estimatedValue),
            'low_stock_count' => $this->inventory->lowStockRows()->count(),
            'negative_stock_count' => $stocks->filter(fn (ProductStock $stock) => (float) $stock->qty < 0)->count(),
            'cost_basis' => 'current_product_purchase_price',
        ];
    }

    private function supplierBalances(?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $entries = SupplierLedgerEntry::query()
            ->with('supplier:id,name,company_name')
            ->when($to, fn (Builder $query) => $query->where('occurred_at', '<=', $to))
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $rows = $entries->groupBy('supplier_id')->map(function (Collection $supplierEntries) use ($from) {
            $openingEntries = $from
                ? $supplierEntries->filter(fn (SupplierLedgerEntry $entry) => $entry->occurred_at->lt($from))
                : collect();
            $periodEntries = $from
                ? $supplierEntries->filter(fn (SupplierLedgerEntry $entry) => $entry->occurred_at->gte($from))
                : $supplierEntries;
            $openingBalance = $this->balance($openingEntries);
            $credits = $this->sumDirection($periodEntries, 'credit');
            $debits = $this->sumDirection($periodEntries, 'debit');

            return [
                'supplier' => $supplierEntries->first()->supplier,
                'opening_balance' => $openingBalance,
                'credits' => $credits,
                'debits' => $debits,
                'balance' => $this->amount($openingBalance + $credits - $debits),
                'last_activity' => $periodEntries->max('occurred_at'),
            ];
        })->sortByDesc('balance')->values();

        return [
            'rows' => $rows,
            'supplier_count' => Supplier::query()->count(),
            'credits' => $this->amount($rows->sum('credits')),
            'debits' => $this->amount($rows->sum('debits')),
            'balance' => $this->amount($rows->sum('balance')),
        ];
    }

    private function taxSummary(?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $events = $this->eventQuery($from, $to);
        $sum = fn (string $type): float => $this->amount(
            (clone $events)->where('event_type', $type)->sum('tax_amount')
        );
        $salesTax = $sum('sale');
        $salesReturnTax = $sum('sale_return');
        $purchaseTax = $sum('purchase_receipt');
        $netSalesTax = $this->amount($salesTax - $salesReturnTax);

        return [
            'sales_tax' => $salesTax,
            'sales_return_tax' => $salesReturnTax,
            'net_sales_tax' => $netSalesTax,
            'purchase_tax' => $purchaseTax,
            'net_tax_estimate' => $this->amount($netSalesTax - $purchaseTax),
            'snapshot_count' => $this->filteredTaxSnapshots($from, $to)->count(),
        ];
    }

    private function cashboxSummary(?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $movements = CashMovement::query();
        $this->applyDateFilter($movements, 'occurred_at', $from, $to);
        $cashIn = $this->amount((clone $movements)->where('direction', 'in')->sum('amount'));
        $cashOut = $this->amount((clone $movements)->where('direction', 'out')->sum('amount'));

        $shifts = CashierShift::query();
        $this->applyDateFilter($shifts, 'opened_at', $from, $to);

        return [
            'cash_in' => $cashIn,
            'cash_out' => $cashOut,
            'expected_cash_movement' => $this->amount($cashIn - $cashOut),
            'shift_count' => (clone $shifts)->count(),
            'open_shifts' => (clone $shifts)->where('status', 'open')->count(),
        ];
    }

    private function purchaseSummary(?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $entries = SupplierLedgerEntry::query();
        $this->applyDateFilter($entries, 'occurred_at', $from, $to);
        $sum = fn (string $type, string $direction): float => $this->amount(
            (clone $entries)
                ->where('entry_type', $type)
                ->where('direction', $direction)
                ->sum('amount_usd')
        );

        return [
            'purchases_total' => $sum('purchase_invoice', 'credit'),
            'purchase_returns_total' => $sum('purchase_return', 'debit'),
            'supplier_payments_total' => $sum('purchase_payment', 'debit'),
            'outstanding_supplier_balance' => $this->amount(
                SupplierLedgerEntry::query()
                    ->when($to, fn (Builder $query) => $query->where('occurred_at', '<=', $to))
                    ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount_usd ELSE -amount_usd END), 0) AS balance")
                    ->value('balance')
            ),
        ];
    }

    private function eventQuery(?CarbonImmutable $from, ?CarbonImmutable $to): Builder
    {
        $query = AccountingEvent::query()->where('status', 'posted');
        $this->applyDateFilter($query, 'occurred_at', $from, $to);

        return $query;
    }

    private function filteredTaxSnapshots(?CarbonImmutable $from, ?CarbonImmutable $to): Builder
    {
        $query = TaxSnapshot::query();
        $this->applyDateFilter($query, 'created_at', $from, $to);

        return $query;
    }

    private function applyDateFilter(
        Builder $query,
        string $column,
        ?CarbonImmutable $from,
        ?CarbonImmutable $to
    ): void {
        $query
            ->when($from, fn (Builder $builder) => $builder->where($column, '>=', $from))
            ->when($to, fn (Builder $builder) => $builder->where($column, '<=', $to));
    }

    private function dateRange(array $filters): array
    {
        $from = ! empty($filters['date_from'])
            ? CarbonImmutable::parse($filters['date_from'])->startOfDay()
            : null;
        $to = ! empty($filters['date_to'])
            ? CarbonImmutable::parse($filters['date_to'])->endOfDay()
            : null;

        return [$from, $to];
    }

    private function sumDirection(Collection $entries, string $direction): float
    {
        return $this->amount(
            $entries->where('direction', $direction)->sum(fn (SupplierLedgerEntry $entry) => $entry->amount_usd)
        );
    }

    private function balance(Collection $entries): float
    {
        return $this->amount(
            $entries->sum(fn (SupplierLedgerEntry $entry) => $entry->direction === 'credit'
                ? (float) $entry->amount_usd
                : -(float) $entry->amount_usd)
        );
    }

    private function amount(mixed $value): float
    {
        return $this->money->normalizeMoney($value);
    }
}
