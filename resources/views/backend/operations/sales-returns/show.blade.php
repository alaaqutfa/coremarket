@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3"><div class="row"><div class="col"><h5 class="mb-0 h6">{{ $salesReturn->return_number }} <span class="badge badge-info">{{ translate(ucfirst($salesReturn->status)) }}</span></h5></div><div class="col text-right"><a class="btn btn-soft-primary btn-sm" href="{{ route('operations.sales-returns') }}">{{ translate('Sales Returns') }}</a></div></div></div>
<div class="alert alert-info">{{ translate('Stock reversal and financial refund posting are separate documented actions.') }}</div>
<div class="card"><div class="card-body"><div class="row"><div class="col-md-4"><strong>{{ translate('Order') }}:</strong> #{{ $salesReturn->order_id }} {{ $salesReturn->order?->code }}</div><div class="col-md-4"><strong>{{ translate('Customer') }}:</strong> {{ $salesReturn->order?->user?->name ?: '-' }}</div><div class="col-md-4"><strong>{{ translate('Type') }}:</strong> {{ translate(ucfirst(str_replace('_', ' ', $salesReturn->return_type))) }}</div></div><div class="row mt-2"><div class="col-md-6"><strong>{{ translate('Reason') }}:</strong> {{ $salesReturn->reason ?: '-' }}</div><div class="col-md-6"><strong>{{ translate('Notes') }}:</strong> {{ $salesReturn->notes ?: '-' }}</div></div></div></div>
<div class="card"><div class="card-header"><h6 class="mb-0">{{ translate('Return items') }}</h6></div><div class="card-body table-responsive"><table class="table table-bordered mb-0"><thead><tr><th>{{ translate('Product') }}</th><th>{{ translate('Variant / SKU / Barcode') }}</th><th>{{ translate('Returned') }}</th><th>{{ translate('Unit Price') }}</th><th>{{ translate('Tax') }}</th><th>{{ translate('Discount') }}</th>@if($canViewReturnFinancials)<th>{{ translate('Cost Price') }}</th><th>{{ translate('Total Cost') }}</th><th>{{ translate('Profit Reversal') }}</th>@endif<th>{{ translate('Stock Reversed') }}</th></tr></thead><tbody>@foreach($salesReturn->items as $item)<tr><td>{{ $item->product?->name ?: '#'.$item->product_id }}</td><td>{{ $item->productStock?->variant ?: $item->variant ?: '-' }}<br><small>{{ $item->productStock?->sku ?: '-' }} / {{ $item->productStock?->barcode ?: $item->product?->barcode ?: '-' }}</small></td><td>{{ coremarket_quantity($item->quantity) }}</td><td>{{ coremarket_money($item->unit_price) }}</td><td>{{ coremarket_money($item->tax_amount) }}</td><td>{{ coremarket_money($item->discount_amount) }}</td>@if($canViewReturnFinancials)<td>@if($item->cost_price === null)<span class="text-warning">{{ translate('Unavailable') }}</span>@else{{ coremarket_money($item->cost_price) }}@endif</td><td>{{ coremarket_money($item->total_cost) }}</td><td>{{ coremarket_money($item->profit_reversal_amount) }}</td>@endif<td>{{ coremarket_quantity($item->stock_reversed_quantity) }}</td></tr>@endforeach</tbody></table></div></div>
<div class="card"><div class="card-body"><div class="row"><div class="col-md-3"><strong>{{ translate('Subtotal') }}:</strong> {{ coremarket_money($salesReturn->subtotal_amount) }}</div><div class="col-md-2"><strong>{{ translate('Tax') }}:</strong> {{ coremarket_money($salesReturn->tax_amount) }}</div><div class="col-md-2"><strong>{{ translate('Discount') }}:</strong> {{ coremarket_money($salesReturn->discount_amount) }}</div>@if($canViewReturnFinancials)<div class="col-md-2"><strong>{{ translate('Total Cost') }}:</strong> {{ coremarket_money($salesReturn->total_cost) }}</div><div class="col-md-3"><strong>{{ translate('Profit Reversal') }}:</strong> {{ coremarket_money($salesReturn->profit_reversal_amount) }}</div>@endif</div><div class="mt-2"><strong>{{ translate('Stock reversal status') }}:</strong> {{ $salesReturn->stock_reversed_at ? translate('Completed') : translate('Not reversed') }}</div></div></div>
@can('sales_returns.complete')
@if(!in_array($salesReturn->status, ['completed', 'rejected', 'cancelled']))
<form method="POST" action="{{ route('operations.sales-returns.complete', $salesReturn) }}" class="mb-3">@csrf <button class="btn btn-success">{{ translate('Complete return and reverse stock') }}</button></form>
@endif
@endcan
@if($canViewRefunds)
<div class="card">
    <div class="card-header"><h6 class="mb-0">{{ translate('Refunds and credit notes') }}</h6></div>
    <div class="card-body">
        <div class="row mb-3">
            <div class="col-md-3"><strong>{{ translate('Return total') }}:</strong> {{ coremarket_money($refundSnapshot['refundable_amount']) }}</div>
            <div class="col-md-3"><strong>{{ translate('Refunded') }}:</strong> {{ coremarket_money($refundSnapshot['refunded_amount']) }}</div>
            <div class="col-md-3"><strong>{{ translate('Remaining') }}:</strong> {{ coremarket_money($refundSnapshot['remaining_amount']) }}</div>
            <div class="col-md-3"><strong>{{ translate('Suggested method') }}:</strong> {{ translate(ucwords(str_replace('_', ' ', $refundSnapshot['preferred_method']))) }}</div>
        </div>

        @if($salesReturn->status === 'completed' && $refundSnapshot['remaining_amount'] > 0)
        <div class="row">
            @if($canCashRefund)
            <div class="col-lg-6">
                <form method="POST" action="{{ route('operations.sales-returns.refund-cash', $salesReturn) }}" class="border rounded p-3 mb-3">
                    @csrf
                    <input type="hidden" name="idempotency_key" value="{{ $cashRefundKey }}">
                    <h6>{{ translate('Refund Cash') }}</h6>
                    <div class="form-group"><label>{{ translate('Amount') }}</label><input class="form-control" type="number" name="amount" min="0.01" step="0.01" max="{{ $refundSnapshot['remaining_amount'] }}" value="{{ coremarket_price($refundSnapshot['remaining_amount']) }}" required></div>
                    <div class="form-group"><label>{{ translate('Open cashbox shift') }}</label><select class="form-control" name="cashier_shift_id" required><option value="">{{ translate('Select shift') }}</option>@foreach($openCashierShifts as $shift)<option value="{{ $shift->id }}">{{ $shift->cashbox?->name ?: '#'.$shift->cashbox_id }} - #{{ $shift->id }}</option>@endforeach</select></div>
                    <div class="form-group"><label>{{ translate('Notes') }}</label><textarea class="form-control" name="notes" rows="2"></textarea></div>
                    @if($openCashierShifts->isEmpty())<div class="alert alert-warning">{{ translate('No open cashbox shift available.') }}</div>@endif
                    <button class="btn btn-danger" @disabled($openCashierShifts->isEmpty())>{{ translate('Post Cash Refund') }}</button>
                </form>
            </div>
            @endif
            @if($canCreditAccount)
            <div class="col-lg-6">
                <form method="POST" action="{{ route('operations.sales-returns.credit-account', $salesReturn) }}" class="border rounded p-3 mb-3">
                    @csrf
                    <input type="hidden" name="idempotency_key" value="{{ $accountCreditKey }}">
                    <h6>{{ translate('Credit Customer Account') }}</h6>
                    <div class="form-group"><label>{{ translate('Amount') }}</label><input class="form-control" type="number" name="amount" min="0.01" step="0.01" max="{{ $refundSnapshot['remaining_amount'] }}" value="{{ coremarket_price($refundSnapshot['remaining_amount']) }}" required></div>
                    <div class="form-group"><label>{{ translate('Notes') }}</label><textarea class="form-control" name="notes" rows="2"></textarea></div>
                    <p class="small text-muted">{{ translate('Creates an AR credit note. No cash movement or customer payment is created.') }}</p>
                    <button class="btn btn-primary">{{ translate('Post Account Credit') }}</button>
                </form>
            </div>
            @endif
        </div>
        @endif

        <div class="table-responsive"><table class="table table-bordered mb-0"><thead><tr><th>{{ translate('Date') }}</th><th>{{ translate('Method') }}</th><th>{{ translate('Amount') }}</th><th>{{ translate('Status') }}</th><th>{{ translate('Reference') }}</th><th>{{ translate('Posted by') }}</th></tr></thead><tbody>
            @forelse($salesReturn->refunds as $refund)
            <tr><td>{{ $refund->created_at }}</td><td>{{ translate(ucwords(str_replace('_', ' ', $refund->refund_method))) }}</td><td>{{ coremarket_money($refund->amount, $refund->currency) }}</td><td>{{ translate(ucfirst($refund->status)) }}</td><td>@if($refund->cash_movement_id){{ translate('Cash movement') }} #{{ $refund->cash_movement_id }}@elseif($refund->customer_ledger_entry_id){{ translate('Credit note') }} #{{ $refund->customer_ledger_entry_id }}@else-@endif</td><td>{{ $refund->refundedBy?->name ?: '-' }}</td></tr>
            @empty<tr><td colspan="6" class="text-center text-muted">{{ translate('No financial refunds posted.') }}</td></tr>@endforelse
        </tbody></table></div>
    </div>
</div>
@endif
<div class="card"><div class="card-header"><h6 class="mb-0">{{ translate('Stock reversal trace') }}</h6></div><div class="card-body table-responsive"><table class="table table-bordered mb-0"><thead><tr><th>{{ translate('Date') }}</th><th>{{ translate('Product') }}</th><th>{{ translate('Reference') }}</th><th>{{ translate('Quantity') }}</th><th>{{ translate('Unit Cost') }}</th><th>{{ translate('Total Cost') }}</th></tr></thead><tbody>@forelse($movements as $movement)<tr><td>{{ $movement->created_at }}</td><td>{{ $movement->product?->name ?: '#'.$movement->product_id }}</td><td>{{ $movement->reference_type }} #{{ $movement->reference_id }}</td><td>{{ coremarket_quantity($movement->quantity) }}</td><td>{{ coremarket_money($movement->unit_cost) }}</td><td>{{ coremarket_money($movement->total_cost) }}</td></tr>@empty<tr><td colspan="6" class="text-center text-muted">{{ translate('No stock reversal movements yet.') }}</td></tr>@endforelse</tbody></table></div></div>
<div class="card"><div class="card-header"><h6 class="mb-0">{{ translate('Accounting event status') }}</h6></div><div class="card-body table-responsive"><table class="table table-bordered mb-0"><thead><tr><th>{{ translate('Status') }}</th><th>{{ translate('Amount') }}</th><th>{{ translate('Cost') }}</th><th>{{ translate('Profit') }}</th><th>{{ translate('Posted at') }}</th></tr></thead><tbody>@forelse($accountingEvents as $event)<tr><td>{{ $event->status }}</td><td>{{ coremarket_money($event->amount, $event->currency) }}</td><td>{{ coremarket_money($event->cost_amount, $event->currency) }}</td><td>{{ coremarket_money($event->profit_amount, $event->currency) }}</td><td>{{ $event->posted_at }}</td></tr>@empty<tr><td colspan="5" class="text-center text-muted">{{ translate('No accounting event yet.') }}</td></tr>@endforelse</tbody></table></div></div>
@endsection
