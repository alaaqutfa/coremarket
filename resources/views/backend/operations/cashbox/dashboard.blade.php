@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3">
    <h5 class="mb-0 h6">{{ translate('Cashbox Dashboard') }}</h5>
</div>

<div class="row gutters-10">
    @foreach([
        'active_cashboxes' => 'Active Cashboxes',
        'open_shifts' => 'Open Shifts',
        'closed_shifts_today' => 'Closed Shifts Today',
        'expected_cash_total' => 'Expected Cash in Open Shifts',
    ] as $key => $label)
        <div class="col-md-3 mb-3"><div class="card h-100"><div class="card-body"><small class="text-muted">{{ translate($label) }}</small><div class="h4 mb-0">{{ number_format((float) $stats[$key], 2) }}</div></div></div></div>
    @endforeach
</div>

<div class="card">
    <div class="card-header"><h6 class="mb-0">{{ translate('Latest Cash Movements') }}</h6></div>
    <div class="card-body table-responsive">
        <table class="table aiz-table mb-0"><thead><tr><th>{{ translate('Date') }}</th><th>{{ translate('Cashbox') }}</th><th>{{ translate('Type') }}</th><th>{{ translate('Direction') }}</th><th>{{ translate('Amount') }}</th></tr></thead>
            <tbody>@forelse($stats['latest_movements'] as $movement)<tr><td>{{ optional($movement->occurred_at)->format('Y-m-d H:i') }}</td><td>{{ $movement->cashbox?->name ?: '-' }}</td><td>{{ translate(ucfirst(str_replace('_', ' ', $movement->movement_type))) }}</td><td>{{ translate(ucfirst($movement->direction)) }}</td><td>{{ number_format((float) $movement->amount, 2) }} {{ $movement->currency }}</td></tr>@empty<tr><td colspan="5" class="text-center text-muted">{{ translate('No cash movements found.') }}</td></tr>@endforelse</tbody>
        </table>
    </div>
</div>
@endsection
