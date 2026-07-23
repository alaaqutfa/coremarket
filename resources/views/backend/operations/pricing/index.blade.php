@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3">
    <div class="row align-items-center">
        <div class="col"><h5 class="mb-0 h6">{{ translate('Price Lists') }}</h5></div>
        <div class="col text-right"><a class="btn btn-primary" href="{{ route('operations.price-lists.create') }}">{{ translate('Create Price List') }}</a></div>
    </div>
</div>
<div class="card"><div class="card-body">
    <div class="alert alert-info">{{ translate('Customer price lists are separate from temporary product sale prices and legacy quantity wholesale tiers.') }}</div>
    <div class="table-responsive"><table class="table aiz-table mb-0">
        <thead><tr><th>{{ translate('Name') }}</th><th>{{ translate('Code') }}</th><th>{{ translate('Type') }}</th><th>{{ translate('Method') }}</th><th>{{ translate('Items') }}</th><th>{{ translate('Customers') }}</th><th>{{ translate('Status') }}</th><th></th></tr></thead>
        <tbody>
        @forelse($priceLists as $list)
            <tr>
                <td>{{ $list->name }} @if($list->is_default)<span class="badge badge-success">{{ translate('Default') }}</span>@endif</td>
                <td>{{ $list->code }}</td><td>{{ translate(ucfirst($list->type)) }}</td>
                <td>{{ translate(ucwords(str_replace('_', ' ', $list->pricing_method))) }}</td>
                <td>{{ $list->items_count }}</td><td>{{ $list->customers_count }}</td>
                <td><span class="badge badge-{{ $list->is_active ? 'success' : 'secondary' }}">{{ $list->is_active ? translate('Active') : translate('Inactive') }}</span></td>
                <td class="text-right"><a class="btn btn-soft-primary btn-sm" href="{{ route('operations.price-lists.show', $list) }}">{{ translate('Manage') }}</a></td>
            </tr>
        @empty
            <tr><td colspan="8" class="text-center text-muted">{{ translate('No price lists found.') }}</td></tr>
        @endforelse
        </tbody>
    </table></div>
    <div class="aiz-pagination">{{ $priceLists->links() }}</div>
</div></div>
@endsection
