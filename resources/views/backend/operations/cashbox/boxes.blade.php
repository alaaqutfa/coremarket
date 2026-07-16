@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3"><div class="row"><div class="col"><h5 class="mb-0 h6">{{ translate('Cashboxes') }}</h5></div>@if($canCreateCashbox)<div class="col text-right"><a href="{{ route('operations.cashboxes.create') }}" class="btn btn-primary">{{ translate('Create Cashbox') }}</a></div>@endif</div></div>
<div class="card"><div class="card-body">
    <form method="GET" class="row gutters-10 mb-3">
        <div class="col-md-4"><input class="form-control" name="search" value="{{ request('search') }}" placeholder="{{ translate('Search name, code or location') }}"></div>
        <div class="col-md-2"><select class="form-control" name="status"><option value="">{{ translate('All statuses') }}</option>@foreach(['active','inactive'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ translate(ucfirst($status)) }}</option>@endforeach</select></div>
        <div class="col-md-3"><select class="form-control" name="assigned_user_id"><option value="">{{ translate('All assigned users') }}</option>@foreach($users as $user)<option value="{{ $user->id }}" @selected((string) request('assigned_user_id') === (string) $user->id)>{{ $user->name }}</option>@endforeach</select></div>
        <div class="col-md-3"><button class="btn btn-soft-primary">{{ translate('Filter') }}</button></div>
    </form>
    <div class="table-responsive"><table class="table aiz-table mb-0"><thead><tr><th>{{ translate('Name') }}</th><th>{{ translate('Code') }}</th><th>{{ translate('Location') }}</th><th>{{ translate('Assigned User') }}</th><th>{{ translate('Status') }}</th><th>{{ translate('Open Shift') }}</th><th></th></tr></thead><tbody>
        @forelse($cashboxes as $cashbox)
            <tr>
                <td>{{ $cashbox->name }}</td><td>{{ $cashbox->code ?: '-' }}</td><td>{{ $cashbox->location ?: '-' }}</td><td>{{ $cashbox->assignedUser?->name ?: '-' }}</td>
                <td><span class="badge badge-{{ $cashbox->isActive() ? 'success' : 'secondary' }}">{{ translate(ucfirst($cashbox->status)) }}</span></td>
                <td>{{ $cashbox->shifts->first()?->opened_at?->format('Y-m-d H:i') ?: '-' }}</td>
                <td class="text-right">
                    <a class="btn btn-soft-primary btn-sm" href="{{ route('operations.cashboxes.show', $cashbox) }}">{{ translate('View') }}</a>
                    @if($canEditCashbox)<a class="btn btn-soft-primary btn-sm" href="{{ route('operations.cashboxes.edit', $cashbox) }}">{{ translate('Edit') }}</a>@endif
                    @if($canOpenShift && $cashbox->isActive() && ! $cashbox->shifts->first())<a class="btn btn-primary btn-sm" href="{{ route('operations.cash-shifts.open.form', $cashbox) }}">{{ translate('Open Shift') }}</a>@endif
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-center text-muted">{{ translate('No cashboxes found.') }}</td></tr>
        @endforelse
    </tbody></table></div><div class="aiz-pagination">{{ $cashboxes->links() }}</div>
</div></div>
@endsection
