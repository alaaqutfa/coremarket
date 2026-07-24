@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3">
    <h5 class="mb-0 h6">{{ translate('Accounting & Operations Reports') }}</h5>
</div>

<div class="card">
    <div class="card-body">
        <form class="row align-items-end" method="GET">
            <div class="col-md-4 form-group">
                <label>{{ translate('Date from') }}</label>
                <input class="form-control" type="date" name="date_from" value="{{ $filters['date_from'] }}">
            </div>
            <div class="col-md-4 form-group">
                <label>{{ translate('Date to') }}</label>
                <input class="form-control" type="date" name="date_to" value="{{ $filters['date_to'] }}">
            </div>
            <div class="col-md-4 form-group">
                <button class="btn btn-primary">{{ translate('Apply filters') }}</button>
                <a class="btn btn-soft-secondary" href="{{ route('operations.accounting.reports') }}">{{ translate('Reset') }}</a>
            </div>
        </form>
    </div>
</div>

<div class="alert alert-info">
    {{ translate('These read-only reports use available operational records in the base currency (USD). COGS and profit are estimates when historical cost snapshots are incomplete.') }}
</div>

@php
    $moneyRows = [
        'profit' => [
            'sales_total' => 'Sales total',
            'sales_returns_total' => 'Sales returns',
            'net_sales' => 'Net sales',
            'net_cogs' => 'Estimated net COGS',
            'gross_profit' => 'Estimated gross profit',
            'expenses_total' => 'Expenses',
            'estimated_net_profit' => 'Estimated net profit',
        ],
        'tax' => [
            'sales_tax' => 'Sales tax',
            'sales_return_tax' => 'Sales return tax',
            'net_sales_tax' => 'Net sales tax',
            'purchase_tax' => 'Purchase tax',
            'net_tax_estimate' => 'Net tax estimate',
        ],
        'cashbox' => [
            'cash_in' => 'Cash in',
            'cash_out' => 'Cash out',
            'expected_cash_movement' => 'Expected cash movement',
        ],
        'purchases' => [
            'purchases_total' => 'Purchases',
            'purchase_returns_total' => 'Purchase returns',
            'supplier_payments_total' => 'Supplier payments',
            'outstanding_supplier_balance' => 'Outstanding supplier balance',
        ],
    ];
@endphp

<div class="row">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">{{ translate('Profit Summary') }}</h6></div>
            <div class="card-body table-responsive">
                <table class="table mb-0">
                    @foreach($moneyRows['profit'] as $key => $label)
                    <tr><th>{{ translate($label) }}</th><td class="text-right">{{ coremarket_money($profit[$key]) }}</td></tr>
                    @endforeach
                    <tr><th>{{ translate('Unknown cost events') }}</th><td class="text-right">{{ coremarket_number($profit['unknown_cost_events'], 0) }}</td></tr>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">{{ translate('Inventory Valuation') }}</h6></div>
            <div class="card-body table-responsive">
                <table class="table mb-0">
                    <tr><th>{{ translate('Products') }}</th><td class="text-right">{{ coremarket_number($inventory['products_count'], 0) }}</td></tr>
                    <tr><th>{{ translate('Variants') }}</th><td class="text-right">{{ coremarket_number($inventory['variants_count'], 0) }}</td></tr>
                    <tr><th>{{ translate('Stock quantity') }}</th><td class="text-right">{{ coremarket_quantity($inventory['stock_quantity']) }}</td></tr>
                    <tr><th>{{ translate('Estimated stock value') }}</th><td class="text-right">{{ coremarket_money($inventory['estimated_stock_value']) }}</td></tr>
                    <tr><th>{{ translate('Low stock variants') }}</th><td class="text-right">{{ coremarket_number($inventory['low_stock_count'], 0) }}</td></tr>
                    <tr><th>{{ translate('Negative stock variants') }}</th><td class="text-right">{{ coremarket_number($inventory['negative_stock_count'], 0) }}</td></tr>
                </table>
                <small class="text-muted">{{ translate('Inventory valuation is a current point-in-time estimate using each product current purchase cost; date filters do not reconstruct historical stock.') }}</small>
            </div>
        </div>
    </div>
</div>

<div class="row mt-3">
    @foreach(['tax' => 'Tax Summary', 'cashbox' => 'Cashbox Summary', 'purchases' => 'Purchase Summary'] as $section => $title)
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">{{ translate($title) }}</h6></div>
            <div class="card-body table-responsive">
                <table class="table mb-0">
                    @foreach($moneyRows[$section] as $key => $label)
                    <tr><th>{{ translate($label) }}</th><td class="text-right">{{ coremarket_money(${$section}[$key]) }}</td></tr>
                    @endforeach
                    @if($section === 'tax')
                    <tr><th>{{ translate('Tax snapshots') }}</th><td class="text-right">{{ coremarket_number($tax['snapshot_count'], 0) }}</td></tr>
                    @elseif($section === 'cashbox')
                    <tr><th>{{ translate('Shifts') }}</th><td class="text-right">{{ coremarket_number($cashbox['shift_count'], 0) }}</td></tr>
                    <tr><th>{{ translate('Open shifts') }}</th><td class="text-right">{{ coremarket_number($cashbox['open_shifts'], 0) }}</td></tr>
                    @endif
                </table>
            </div>
        </div>
    </div>
    @endforeach
</div>

<div class="card mt-3">
    <div class="card-header"><h6 class="mb-0">{{ translate('Supplier Balances') }}</h6></div>
    <div class="card-body table-responsive">
        <table class="table aiz-table mb-0">
            <thead><tr>
                <th>{{ translate('Supplier') }}</th>
                <th>{{ translate('Opening balance') }}</th>
                <th>{{ translate('Credits') }}</th>
                <th>{{ translate('Debits') }}</th>
                <th>{{ translate('Closing balance') }}</th>
                <th>{{ translate('Last activity') }}</th>
            </tr></thead>
            <tbody>
                @forelse($suppliers['rows'] as $row)
                <tr>
                    <td>{{ $row['supplier']?->name ?: translate('Unknown supplier') }}</td>
                    <td>{{ coremarket_money($row['opening_balance']) }}</td>
                    <td>{{ coremarket_money($row['credits']) }}</td>
                    <td>{{ coremarket_money($row['debits']) }}</td>
                    <td>{{ coremarket_money($row['balance']) }}</td>
                    <td>{{ $row['last_activity'] ?: '-' }}</td>
                </tr>
                @empty
                <tr><td colspan="6" class="text-center text-muted">{{ translate('No supplier ledger entries are available for this period.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
        <small class="text-muted">{{ translate('Supplier balances use supplier ledger entries only. No historical purchase backfill is created.') }}</small>
    </div>
</div>

<div class="alert alert-warning mb-0">
    {{ translate('Tax values are informational estimates and are not an official VAT filing. Full double-entry accounting remains outside this operational report.') }}
</div>
@endsection
