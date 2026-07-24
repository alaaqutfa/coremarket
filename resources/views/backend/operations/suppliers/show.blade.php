@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3">
    <div class="row">
        <div class="col"><h5 class="mb-0 h6">{{ $supplier->name }}</h5></div>
        @can('suppliers.edit')<div class="col text-right"><a href="{{ route('operations.suppliers.edit', $supplier) }}" class="btn btn-soft-primary btn-sm">{{ translate('Edit Supplier') }}</a></div>@endcan
    </div>
</div>

<div class="row">
    <div class="col-md-4"><div class="card"><div class="card-body">
        <div class="text-muted">{{ translate('Supplier Balance') }}</div>
        <h3 class="mb-1">{{ coremarket_money($balance, 'USD') }}</h3>
        <small>{{ translate('Credits increase the amount owed; payments and returns decrease it.') }}</small>
    </div></div></div>
    <div class="col-md-8"><div class="card"><div class="card-body">
        <strong>{{ $supplier->company_name ?: $supplier->name }}</strong>
        <div>{{ $supplier->email ?: '-' }} · {{ $supplier->phone ?: '-' }}</div>
        <div class="text-muted">{{ $supplier->address ?: '-' }}</div>
    </div></div></div>
</div>

<div class="card"><div class="card-body">
    <form method="GET" action="{{ route('operations.suppliers.statement.pdf', $supplier) }}" class="row align-items-end">
        <div class="col-md-4 form-group mb-md-0"><label>{{ translate('Statement From') }}</label><input class="form-control" type="date" name="date_from"></div>
        <div class="col-md-4 form-group mb-md-0"><label>{{ translate('Statement To') }}</label><input class="form-control" type="date" name="date_to"></div>
        <div class="col-md-4"><button class="btn btn-soft-primary">{{ translate('Download Statement PDF') }}</button></div>
    </form>
</div></div>

@can('supplier_payments.create')
<div class="card"><div class="card-header"><h6 class="mb-0">{{ translate('Record Supplier Payment') }}</h6></div><div class="card-body">
    @if($errors->has('payment'))<div class="alert alert-danger">{{ $errors->first('payment') }}</div>@endif
    <form method="POST" action="{{ route('operations.suppliers.payments.store', $supplier) }}">@csrf
        <input type="hidden" name="payment_key" value="{{ old('payment_key', $paymentKey) }}">
        <div class="row">
            <div class="col-md-3 form-group"><label>{{ translate('Purchase Order') }}</label><select class="form-control" name="purchase_order_id"><option value="">{{ translate('General supplier payment') }}</option>@foreach($purchaseOrders as $order)<option value="{{ $order->id }}" @selected(old('purchase_order_id') == $order->id)>{{ $order->purchase_number }}</option>@endforeach</select></div>
            <div class="col-md-2 form-group"><label>{{ translate('Amount') }}</label><input class="form-control" type="number" step="0.01" min="0.01" name="amount" value="{{ old('amount') }}" required></div>
            <div class="col-md-1 form-group"><label>{{ translate('Currency') }}</label><input class="form-control" name="currency" value="{{ old('currency', 'USD') }}" required></div>
            <div class="col-md-2 form-group"><label>{{ translate('Exchange Rate') }}</label><input class="form-control" type="number" step="0.000001" min="0.000001" name="exchange_rate" value="{{ old('exchange_rate', 1) }}" required></div>
            <div class="col-md-2 form-group"><label>{{ translate('Method') }}</label><select class="form-control" name="payment_method">@foreach(['cash','bank_transfer','card','cheque','other'] as $method)<option value="{{ $method }}" @selected(old('payment_method') === $method)>{{ translate(ucwords(str_replace('_', ' ', $method))) }}</option>@endforeach</select></div>
            <div class="col-md-2 form-group"><label>{{ translate('Paid At') }}</label><input class="form-control" type="datetime-local" name="paid_at" value="{{ old('paid_at', now()->format('Y-m-d\TH:i')) }}" required></div>
        </div>
        <div class="row"><div class="col-md-4 form-group"><label>{{ translate('Reference') }}</label><input class="form-control" name="payment_reference" value="{{ old('payment_reference') }}"></div><div class="col-md-8 form-group"><label>{{ translate('Notes') }}</label><input class="form-control" name="notes" value="{{ old('notes') }}"></div></div>
        <button class="btn btn-primary">{{ translate('Record Payment') }}</button>
    </form>
</div></div>
@endcan

<div class="card"><div class="card-header"><h6 class="mb-0">{{ translate('Supplier Ledger') }}</h6></div><div class="card-body table-responsive">
    <table class="table table-bordered mb-0"><thead><tr><th>{{ translate('Date') }}</th><th>{{ translate('Type') }}</th><th>{{ translate('Description') }}</th><th>{{ translate('Debit') }}</th><th>{{ translate('Credit') }}</th><th>{{ translate('USD Amount') }}</th></tr></thead><tbody>
    @forelse($ledgerEntries as $entry)<tr><td>{{ optional($entry->occurred_at)->format('Y-m-d H:i') }}</td><td>{{ translate(ucwords(str_replace('_', ' ', $entry->entry_type))) }}</td><td>{{ $entry->description }}</td><td>{{ $entry->direction === 'debit' ? coremarket_money($entry->amount, $entry->currency) : '-' }}</td><td>{{ $entry->direction === 'credit' ? coremarket_money($entry->amount, $entry->currency) : '-' }}</td><td>{{ coremarket_money($entry->amount_usd, 'USD') }}</td></tr>
    @empty<tr><td colspan="6" class="text-center text-muted">{{ translate('No supplier ledger entries yet.') }}</td></tr>@endforelse
    </tbody></table>
    <div class="aiz-pagination mt-3">{{ $ledgerEntries->links() }}</div>
</div></div>

<div class="row">
    <div class="col-md-6"><div class="card"><div class="card-header"><h6 class="mb-0">{{ translate('Recent Payments') }}</h6></div><div class="card-body table-responsive"><table class="table table-bordered mb-0"><thead><tr><th>{{ translate('Date') }}</th><th>{{ translate('Amount') }}</th><th>{{ translate('Method') }}</th></tr></thead><tbody>@forelse($payments as $payment)<tr><td>{{ optional($payment->paid_at)->format('Y-m-d') }}</td><td>{{ coremarket_money($payment->amount, $payment->currency) }}</td><td>{{ $payment->payment_method ?: '-' }}</td></tr>@empty<tr><td colspan="3" class="text-center text-muted">{{ translate('No payments yet.') }}</td></tr>@endforelse</tbody></table></div></div></div>
    <div class="col-md-6"><div class="card"><div class="card-header"><h6 class="mb-0">{{ translate('Recent Purchase Returns') }}</h6></div><div class="card-body table-responsive"><table class="table table-bordered mb-0"><thead><tr><th>{{ translate('Return') }}</th><th>{{ translate('Status') }}</th><th>{{ translate('Total') }}</th></tr></thead><tbody>@forelse($purchaseReturns as $return)<tr><td><a href="{{ route('operations.purchase-returns.show', $return) }}">{{ $return->return_number }}</a></td><td>{{ $return->status }}</td><td>{{ coremarket_money($return->total, $return->currency) }}</td></tr>@empty<tr><td colspan="3" class="text-center text-muted">{{ translate('No purchase returns yet.') }}</td></tr>@endforelse</tbody></table></div></div></div>
</div>
@endsection
