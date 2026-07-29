@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3 d-flex align-items-center justify-content-between">
    <div>
        <h5 class="mb-1 h6">{{ translate('Customer Ledger') }}: {{ $customer->name }}</h5>
        <small class="text-muted">{{ $customer->email }} | {{ $customer->phone ?: translate('No phone') }}</small>
    </div>
    <div>
        @if(auth()->user()?->user_type === 'admin' || auth()->user()?->can('customer_credit.view'))
            <a class="btn btn-soft-primary" href="{{ route('operations.customers.account-profile.show', $customer) }}">{{ translate('Credit Profile') }}</a>
        @endif
        <a class="btn btn-soft-info" href="{{ route('operations.customers.statement.pdf', $customer) }}">{{ translate('Export Statement PDF') }}</a>
    </div>
</div>

@if($profile || $featureSnapshot['credit_limits_enabled'] || $featureSnapshot['payment_terms_enabled'])
<div class="card">
    <div class="card-body">
        <div class="row">
            <div class="col-md-3"><small class="text-muted">{{ translate('Account status') }}</small><div>{{ translate(ucwords(str_replace('_', ' ', $profile?->account_status ?: 'Not configured'))) }}</div></div>
            <div class="col-md-3"><small class="text-muted">{{ translate('Credit limit') }}</small><div>{{ $profile?->credit_limit !== null ? coremarket_money($profile->credit_limit) : translate('Not set') }}</div></div>
            <div class="col-md-3"><small class="text-muted">{{ translate('Available credit') }}</small><div>{{ $availableCredit !== null ? coremarket_money($availableCredit) : translate('Not limited') }}</div></div>
            <div class="col-md-3"><small class="text-muted">{{ translate('Overdue estimate') }}</small><div>{{ coremarket_money($overdueBalance) }}</div></div>
        </div>
        <small class="text-muted">{{ translate('Next due date') }}: {{ $nextDueDate?->toDateString() ?: translate('Not available') }}</small>
    </div>
</div>
@endif

<div class="row">
    <div class="col-md-4">
        <div class="card"><div class="card-body">
            <small class="text-muted">{{ translate('Current balance') }}</small>
            <div class="h4 mb-0">{{ coremarket_money($balance) }}</div>
        </div></div>
    </div>
    <div class="col-md-8">
        <div class="card"><div class="card-body">
            <div class="row">
                @foreach(['current' => 'Current', '1_30' => '1-30', '31_60' => '31-60', '61_90' => '61-90', '90_plus' => '90+'] as $key => $label)
                    <div class="col">
                        <small class="text-muted">{{ $label }}</small>
                        <div>{{ coremarket_money($aging[$key]) }}</div>
                    </div>
                @endforeach
            </div>
            <small class="text-muted">
                {{ $featureSnapshot['payment_terms_enabled']
                    ? translate('Aging uses saved invoice due-date snapshots when available.')
                    : translate('Aging is estimated from invoice posting date because payment terms are disabled.') }}
            </small>
        </div></div>
    </div>
</div>

@if(auth()->user()?->user_type === 'admin' || auth()->user()?->can('customer_payments.create'))
<div class="card">
    <div class="card-header"><h6 class="mb-0">{{ translate('Record Customer Payment') }}</h6></div>
    <div class="card-body">
        <form method="POST" action="{{ route('operations.customers.payments.store', $customer) }}">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ $paymentKey }}">
            <div class="row">
                <div class="col-md-3 form-group">
                    <label>{{ translate('Amount') }}</label>
                    <input class="form-control" type="number" step="0.01" min="0.01" name="amount" value="{{ old('amount') }}" required>
                </div>
                <div class="col-md-3 form-group">
                    <label>{{ translate('Payment method') }}</label>
                    <select class="form-control" name="payment_method" required>
                        @foreach(['cash' => 'Cash', 'bank_transfer' => 'Bank transfer', 'cheque' => 'Cheque', 'card_manual' => 'Manual card', 'other' => 'Other'] as $value => $label)
                            <option value="{{ $value }}">{{ translate($label) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3 form-group">
                    <label>{{ translate('Cashbox shift') }}</label>
                    <select class="form-control" name="cashier_shift_id">
                        <option value="">{{ translate('Required for cash only') }}</option>
                        @foreach($openShifts as $shift)
                            <option value="{{ $shift->id }}">{{ $shift->cashbox?->name }} - #{{ $shift->id }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3 form-group">
                    <label>{{ translate('Reference') }}</label>
                    <input class="form-control" name="reference" value="{{ old('reference') }}">
                </div>
            </div>
            @if($invoiceEntries->isNotEmpty())
                <div class="table-responsive mb-3">
                    <table class="table table-sm">
                        <thead><tr><th>{{ translate('Invoice') }}</th><th>{{ translate('Outstanding') }}</th><th>{{ translate('Allocate') }}</th></tr></thead>
                        <tbody>
                            @foreach($invoiceEntries as $invoice)
                                <tr>
                                    <td>{{ $invoice->order?->code ?: '#'.$invoice->order_id }}</td>
                                    <td>{{ coremarket_money($invoice->outstanding_amount) }}</td>
                                    <td><input class="form-control form-control-sm" type="number" min="0" step="0.01" name="allocations[{{ $invoice->id }}]" value="0"></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            <div class="form-group">
                <label>{{ translate('Notes') }}</label>
                <textarea class="form-control" name="notes" rows="2">{{ old('notes') }}</textarea>
            </div>
            <button class="btn btn-primary">{{ translate('Record Payment') }}</button>
        </form>
    </div>
</div>
@endif

<div class="card">
    <div class="card-header"><h6 class="mb-0">{{ translate('Ledger Entries') }}</h6></div>
    <div class="card-body">
        <form class="row align-items-end mb-3" method="GET">
            <div class="col-md-3 form-group"><label>{{ translate('Date from') }}</label><input class="form-control" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"></div>
            <div class="col-md-3 form-group"><label>{{ translate('Date to') }}</label><input class="form-control" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}"></div>
            <div class="col-md-3 form-group">
                <label>{{ translate('Type') }}</label>
                <select class="form-control" name="entry_type">
                    <option value="">{{ translate('All') }}</option>
                    @foreach(['invoice','payment','credit_note','debit_adjustment','credit_adjustment','opening_balance'] as $type)
                        <option value="{{ $type }}" @selected(($filters['entry_type'] ?? '') === $type)>{{ translate(ucwords(str_replace('_', ' ', $type))) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3 form-group"><button class="btn btn-primary">{{ translate('Apply filters') }}</button></div>
        </form>
        <div class="table-responsive">
            <table class="table aiz-table">
                <thead><tr><th>{{ translate('Date') }}</th><th>{{ translate('Type') }}</th><th>{{ translate('Reference') }}</th><th>{{ translate('Description') }}</th><th>{{ translate('Debit') }}</th><th>{{ translate('Credit') }}</th></tr></thead>
                <tbody>
                    @forelse($entries as $entry)
                        <tr>
                            <td>{{ $entry->occurred_at?->format('Y-m-d H:i') }}</td>
                            <td>{{ translate(ucwords(str_replace('_', ' ', $entry->entry_type))) }}</td>
                            <td>{{ $entry->order?->code ?: $entry->payment?->reference ?: $entry->salesReturn?->return_number ?: '#'.$entry->id }}</td>
                            <td>{{ $entry->description }}</td>
                            <td>{{ $entry->direction === 'debit' ? coremarket_money($entry->amount) : '-' }}</td>
                            <td>{{ $entry->direction === 'credit' ? coremarket_money($entry->amount) : '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted">{{ translate('No ledger entries found.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="aiz-pagination">{{ $entries->links() }}</div>
        </div>
    </div>
</div>
@endsection
