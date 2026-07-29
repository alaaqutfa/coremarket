@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3"><div class="row"><div class="col"><h5 class="mb-0 h6">{{ translate('Stock Transfers') }}</h5></div><div class="col text-right">@can('inventory.stock_transfers.create')<a class="btn btn-primary" href="{{ route('operations.inventory.stock-transfers.create') }}">{{ translate('New Transfer') }}</a>@endcan</div></div></div>
<div class="card"><div class="card-body">
    <form class="row gutters-10 mb-3"><div class="col-md-4"><select class="form-control" name="status"><option value="">{{ translate('All Statuses') }}</option>@foreach(['draft','pending_approval','approved','shipped','received','cancelled','rejected'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ translate(ucwords(str_replace('_',' ',$status))) }}</option>@endforeach</select></div><div class="col-md-2"><button class="btn btn-soft-primary">{{ translate('Filter') }}</button></div></form>
    <div class="table-responsive"><table class="table table-bordered"><thead><tr><th>{{ translate('Reference') }}</th><th>{{ translate('From') }}</th><th>{{ translate('To') }}</th><th>{{ translate('Status') }}</th><th>{{ translate('Requested By') }}</th><th>{{ translate('Date') }}</th><th></th></tr></thead>
    <tbody>@forelse($transfers as $transfer)<tr><td>{{ $transfer->reference_no }}</td><td>{{ $transfer->fromBranch?->name }}</td><td>{{ $transfer->toBranch?->name }}</td><td>{{ translate(ucwords(str_replace('_',' ',$transfer->status))) }}</td><td>{{ $transfer->requester?->name ?: '-' }}</td><td>{{ $transfer->created_at }}</td><td><a class="btn btn-soft-primary btn-sm" href="{{ route('operations.inventory.stock-transfers.show', $transfer) }}">{{ translate('View') }}</a></td></tr>@empty<tr><td colspan="7" class="text-center text-muted">{{ translate('No stock transfers found.') }}</td></tr>@endforelse</tbody></table></div>
    {{ $transfers->links() }}
</div></div>
@endsection
