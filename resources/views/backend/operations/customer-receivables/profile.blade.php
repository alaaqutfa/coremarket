@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3 d-flex align-items-center justify-content-between">
    <div>
        <h5 class="mb-1 h6">{{ translate('Customer Credit Profile') }}: {{ $customer->name }}</h5>
        <small class="text-muted">{{ $customer->email }} | {{ $customer->phone ?: translate('No phone') }}</small>
    </div>
    <a class="btn btn-soft-primary" href="{{ route('operations.customers.receivables.show', $customer) }}">
        {{ translate('Open Ledger') }}
    </a>
</div>

@if(!$featureSnapshot['credit_limits_enabled'])
    <div class="alert alert-warning">
        {{ translate('Credit limit enforcement is disabled. Profile settings are saved but do not block manual AR posting until the feature is enabled.') }}
    </div>
@endif

<div class="row">
    @foreach([
        ['label' => 'Current balance', 'value' => coremarket_money($balance)],
        ['label' => 'Credit limit', 'value' => $profile?->credit_limit !== null ? coremarket_money($profile->credit_limit) : translate('Not set')],
        ['label' => 'Available credit', 'value' => $availableCredit !== null ? coremarket_money($availableCredit) : translate('Not limited')],
        ['label' => 'Overdue estimate', 'value' => coremarket_money($overdueBalance)],
    ] as $metric)
        <div class="col-md-3">
            <div class="card"><div class="card-body">
                <small class="text-muted">{{ translate($metric['label']) }}</small>
                <div class="h5 mb-0">{{ $metric['value'] }}</div>
            </div></div>
        </div>
    @endforeach
</div>

<div class="card">
    <div class="card-header"><h6 class="mb-0">{{ translate('Credit Policy') }}</h6></div>
    <div class="card-body">
        @if(auth()->user()?->user_type === 'admin' || auth()->user()?->can('customer_credit.manage'))
            <form method="POST" action="{{ route('operations.customers.account-profile.update', $customer) }}">
                @csrf
                @method('PUT')
                <div class="row">
                    <div class="col-md-3 form-group">
                        <label class="d-block">{{ translate('Credit allowed') }}</label>
                        <label class="aiz-switch aiz-switch-success mb-0">
                            <input type="checkbox" name="is_credit_allowed" value="1" @checked(old('is_credit_allowed', $profile?->is_credit_allowed))>
                            <span class="slider round"></span>
                        </label>
                    </div>
                    <div class="col-md-3 form-group">
                        <label>{{ translate('Credit limit') }}</label>
                        <input class="form-control" type="number" step="0.01" min="0" name="credit_limit" value="{{ old('credit_limit', $profile?->credit_limit) }}">
                    </div>
                    <div class="col-md-3 form-group">
                        <label>{{ translate('Payment terms days') }}</label>
                        <input class="form-control" type="number" min="0" max="3650" name="payment_terms_days" value="{{ old('payment_terms_days', $profile?->payment_terms_days) }}">
                    </div>
                    <div class="col-md-3 form-group">
                        <label>{{ translate('Account status') }}</label>
                        <select class="form-control" name="account_status" required>
                            @foreach(['active' => 'Active', 'on_hold' => 'On hold', 'blocked' => 'Blocked'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('account_status', $profile?->account_status ?: 'active') === $value)>{{ translate($label) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4 form-group">
                        <label>{{ translate('Default payment method') }}</label>
                        <select class="form-control" name="default_payment_method">
                            <option value="">{{ translate('Not set') }}</option>
                            @foreach(['cash' => 'Cash', 'bank_transfer' => 'Bank transfer', 'cheque' => 'Cheque', 'card_manual' => 'Manual card', 'other' => 'Other'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('default_payment_method', $profile?->default_payment_method) === $value)>{{ translate($label) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-8 form-group">
                        <label>{{ translate('Notes') }}</label>
                        <textarea class="form-control" name="notes" rows="2">{{ old('notes', $profile?->notes) }}</textarea>
                    </div>
                </div>
                <button class="btn btn-primary">{{ translate('Save Credit Profile') }}</button>
            </form>
        @else
            <div class="alert alert-info mb-0">{{ translate('You have view-only access to this customer credit profile.') }}</div>
        @endif
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="row">
            <div class="col-md-4"><strong>{{ translate('Next due date') }}:</strong> {{ $nextDueDate?->toDateString() ?: translate('Not available') }}</div>
            <div class="col-md-4"><strong>{{ translate('Last payment') }}:</strong> {{ $lastPayment ? coremarket_money($lastPayment->amount) : translate('No payment recorded') }}</div>
            <div class="col-md-4"><strong>{{ translate('Last reviewed') }}:</strong> {{ $profile?->last_reviewed_at?->format('Y-m-d H:i') ?: translate('Not reviewed') }}</div>
        </div>
    </div>
</div>
@endsection
