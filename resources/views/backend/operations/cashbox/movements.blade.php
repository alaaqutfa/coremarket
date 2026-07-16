@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3"><h5 class="mb-0 h6">{{ translate('Cash Movements') }}</h5></div>
<div class="card"><div class="card-body">
    <form method="GET" class="row gutters-10 mb-3">
        <div class="col-md-2"><select class="form-control" name="cashbox_id"><option value="">{{ translate('All cashboxes') }}</option>@foreach($cashboxes as $cashbox)<option value="{{ $cashbox->id }}" @selected((string) request('cashbox_id') === (string) $cashbox->id)>{{ $cashbox->name }}</option>@endforeach</select></div>
        <div class="col-md-2"><select class="form-control" name="cashier_shift_id"><option value="">{{ translate('All shifts') }}</option>@foreach($shifts as $shift)<option value="{{ $shift->id }}" @selected((string) request('cashier_shift_id') === (string) $shift->id)>#{{ $shift->id }} ({{ $shift->status }})</option>@endforeach</select></div>
        <div class="col-md-2"><select class="form-control" name="movement_type"><option value="">{{ translate('All types') }}</option>@foreach(['opening','cash_in','cash_out','adjustment','closing_difference'] as $type)<option value="{{ $type }}" @selected(request('movement_type') === $type)>{{ translate(ucfirst(str_replace('_', ' ', $type))) }}</option>@endforeach</select></div>
        <div class="col-md-2"><select class="form-control" name="direction"><option value="">{{ translate('All directions') }}</option>@foreach(['in','out','neutral'] as $direction)<option value="{{ $direction }}" @selected(request('direction') === $direction)>{{ translate(ucfirst($direction)) }}</option>@endforeach</select></div>
        <div class="col-md-2"><select class="form-control" name="created_by"><option value="">{{ translate('All users') }}</option>@foreach($users as $user)<option value="{{ $user->id }}" @selected((string) request('created_by') === (string) $user->id)>{{ $user->name }}</option>@endforeach</select></div>
        <div class="col-md-1"><input type="date" class="form-control" name="date_from" value="{{ request('date_from') }}"></div><div class="col-md-1"><input type="date" class="form-control" name="date_to" value="{{ request('date_to') }}"></div><div class="col-md-12 mt-2"><button class="btn btn-soft-primary">{{ translate('Filter') }}</button></div>
    </form>
    <div class="table-responsive"><table class="table aiz-table mb-0"><thead><tr><th>{{ translate('Date') }}</th><th>{{ translate('Cashbox') }}</th><th>{{ translate('Shift') }}</th><th>{{ translate('Type') }}</th><th>{{ translate('Direction') }}</th><th>{{ translate('Amount') }}</th><th>{{ translate('Description') }}</th><th>{{ translate('Created By') }}</th></tr></thead><tbody>
        @forelse($movements as $movement)<tr><td>{{ optional($movement->occurred_at)->format('Y-m-d H:i') }}</td><td>{{ $movement->cashbox?->name ?: '-' }}</td><td>{{ $movement->shift ? '#' . $movement->shift->id : '-' }}</td><td><span class="badge badge-info">{{ translate(ucfirst(str_replace('_', ' ', $movement->movement_type))) }}</span></td><td>{{ translate(ucfirst($movement->direction)) }}</td><td>{{ number_format((float) $movement->amount, 2) }} {{ $movement->currency }}</td><td>{{ $movement->description ?: '-' }}</td><td>{{ $movement->creator?->name ?: '-' }}</td></tr>@empty<tr><td colspan="8" class="text-center text-muted">{{ translate('No cash movements found.') }}</td></tr>@endforelse
    </tbody></table></div><div class="aiz-pagination">{{ $movements->links() }}</div>
</div></div>
@endsection
