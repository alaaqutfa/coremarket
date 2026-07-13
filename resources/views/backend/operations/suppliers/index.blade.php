@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3">
    <div class="row">
        <div class="col"><h5 class="mb-0 h6">{{ translate('Suppliers') }}</h5></div>
        @can('suppliers.create')<div class="col text-right"><a class="btn btn-primary" href="{{ route('operations.suppliers.create') }}">{{ translate('Add Supplier') }}</a></div>@endcan
    </div>
</div>
<div class="card">
    <div class="card-body">
        <form class="row gutters-10 mb-3" method="GET">
            <div class="col-md-6"><input class="form-control" name="search" value="{{ request('search') }}" placeholder="{{ translate('Search name, company, email or phone') }}"></div>
            <div class="col-md-3"><select class="form-control" name="status"><option value="">{{ translate('All statuses') }}</option><option value="active" @selected(request('status') === 'active')>{{ translate('Active') }}</option><option value="inactive" @selected(request('status') === 'inactive')>{{ translate('Inactive') }}</option></select></div>
            <div class="col-md-3"><button class="btn btn-soft-primary">{{ translate('Filter') }}</button></div>
        </form>
        <div class="table-responsive">
            <table class="table aiz-table mb-0">
                <thead><tr><th>{{ translate('Name') }}</th><th>{{ translate('Company') }}</th><th>{{ translate('Contact') }}</th><th>{{ translate('Purchase Orders') }}</th><th>{{ translate('Status') }}</th><th></th></tr></thead>
                <tbody>@forelse($suppliers as $supplier)<tr>
                    <td>{{ $supplier->name }}</td><td>{{ $supplier->company_name ?: '-' }}</td><td><div>{{ $supplier->email ?: '-' }}</div><small>{{ $supplier->phone }}</small></td><td>{{ $supplier->purchase_orders_count }}</td>
                    <td><span class="badge badge-{{ $supplier->is_active ? 'success' : 'secondary' }}">{{ $supplier->is_active ? translate('Active') : translate('Inactive') }}</span></td>
                    <td class="text-right">@can('suppliers.edit')<a class="btn btn-soft-primary btn-sm" href="{{ route('operations.suppliers.edit', $supplier) }}">{{ translate('Edit') }}</a>@endcan</td>
                </tr>@empty<tr><td colspan="6" class="text-center text-muted">{{ translate('No suppliers found.') }}</td></tr>@endforelse</tbody>
            </table>
        </div>
        <div class="aiz-pagination">{{ $suppliers->links() }}</div>
    </div>
</div>
@endsection
