@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3">
    <h5 class="mb-0 h6">{{ translate('Customer Receivables') }}</h5>
</div>

<div class="alert alert-info">
    {{ translate('Customer AR is an optional operational ledger. Orders are posted manually and no historical backfill is created.') }}
</div>

<div class="row">
    @foreach([
        'total' => 'Total outstanding',
        'current' => 'Current',
        '1_30' => '1-30 days',
        '31_60' => '31-60 days',
        '61_90' => '61-90 days',
        '90_plus' => '90+ days',
    ] as $key => $label)
        <div class="col-md-4 col-xl-2">
            <div class="card">
                <div class="card-body">
                    <small class="text-muted">{{ translate($label) }}</small>
                    <div class="h5 mb-0">{{ coremarket_money($aging[$key]) }}</div>
                </div>
            </div>
        </div>
    @endforeach
</div>

@if($featureSnapshot['credit_limits_enabled'])
<div class="row">
    <div class="col-md-3"><div class="card"><div class="card-body"><small class="text-muted">{{ translate('Assigned credit limits') }}</small><div class="h5 mb-0">{{ coremarket_money($creditSummary['total_credit_limit']) }}</div></div></div></div>
    <div class="col-md-3"><div class="card"><div class="card-body"><small class="text-muted">{{ translate('Available credit estimate') }}</small><div class="h5 mb-0">{{ coremarket_money($creditSummary['available_credit']) }}</div></div></div></div>
    <div class="col-md-3"><div class="card"><div class="card-body"><small class="text-muted">{{ translate('Credit profiles') }}</small><div class="h5 mb-0">{{ coremarket_number($creditSummary['profiles_count'], 0) }}</div></div></div></div>
    <div class="col-md-3"><div class="card"><div class="card-body"><small class="text-muted">{{ translate('Overdue estimate') }}</small><div class="h5 mb-0">{{ coremarket_money($creditSummary['overdue_balance']) }}</div></div></div></div>
</div>
@endif

<div class="card">
    <div class="card-header">
        <h6 class="mb-0">{{ translate('Customers with ledger activity') }}</h6>
        <span class="badge badge-inline badge-info">{{ coremarket_number($customersWithBalance, 0) }} {{ translate('with balance') }}</span>
    </div>
    <div class="card-body table-responsive">
        <table class="table aiz-table mb-0">
            <thead><tr>
                <th>{{ translate('Customer') }}</th>
                <th>{{ translate('Email') }}</th>
                <th>{{ translate('Balance') }}</th>
                <th>{{ translate('Credit status') }}</th>
                <th>{{ translate('Available credit') }}</th>
                <th>{{ translate('Overdue') }}</th>
                <th class="text-right">{{ translate('Actions') }}</th>
            </tr></thead>
            <tbody>
                @forelse($customers as $customer)
                    <tr>
                        <td>{{ $customer->name }}</td>
                        <td>{{ $customer->email }}</td>
                        <td>{{ coremarket_money($customer->receivable_balance) }}</td>
                        <td>{{ translate(ucwords(str_replace('_', ' ', $customer->customerAccountProfile?->account_status ?: 'Not configured'))) }}</td>
                        <td>{{ $customer->available_credit !== null ? coremarket_money($customer->available_credit) : '-' }}</td>
                        <td>{{ coremarket_money($customer->overdue_balance) }}</td>
                        <td class="text-right">
                            <a class="btn btn-soft-primary btn-sm" href="{{ route('operations.customers.receivables.show', $customer) }}">
                                {{ translate('Open Ledger') }}
                            </a>
                            @if(auth()->user()?->user_type === 'admin' || auth()->user()?->can('customer_credit.view'))
                                <a class="btn btn-soft-info btn-sm" href="{{ route('operations.customers.account-profile.show', $customer) }}">
                                    {{ translate('Credit Profile') }}
                                </a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted">{{ translate('No customer ledger entries or credit profiles are available.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="aiz-pagination">{{ $customers->links() }}</div>
    </div>
</div>
@endsection
