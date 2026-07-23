@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3"><div class="row align-items-center">
    <div class="col"><h5 class="mb-0 h6">{{ translate('Product Families') }}</h5></div>
    <div class="col text-right"><a class="btn btn-primary" href="{{ route('operations.inventory.families.create') }}">{{ translate('Create Family') }}</a></div>
</div></div>
<div class="card"><div class="card-body">
    <div class="alert alert-info">{{ translate('Families are operational inventory classifications. Storefront categories remain separate and unchanged.') }}</div>
    <div class="table-responsive"><table class="table aiz-table mb-0">
        <thead><tr><th>{{ translate('Family / Sub Family') }}</th><th>{{ translate('Code') }}</th><th>{{ translate('Level') }}</th><th>{{ translate('Products') }}</th><th>{{ translate('Status') }}</th><th></th></tr></thead>
        <tbody>
        @forelse($families as $family)
            <tr class="font-weight-bold">
                <td>{{ $family->name }}</td><td>{{ $family->code ?: '-' }}</td><td>{{ translate('Family') }}</td>
                <td>{{ $family->products()->count() }}</td><td><span class="badge badge-{{ $family->is_active ? 'success' : 'secondary' }}">{{ $family->is_active ? translate('Active') : translate('Inactive') }}</span></td>
                <td class="text-right">
                    <a class="btn btn-soft-primary btn-sm" href="{{ route('operations.inventory.families.create', ['level' => 'sub_family', 'parent_id' => $family->id]) }}">{{ translate('Add Sub Family') }}</a>
                    <a class="btn btn-soft-secondary btn-sm" href="{{ route('operations.inventory.families.edit', $family) }}">{{ translate('Edit') }}</a>
                    <form class="d-inline" method="POST" action="{{ route('operations.inventory.families.toggle', $family) }}">@csrf @method('PATCH')<button class="btn btn-soft-{{ $family->is_active ? 'danger' : 'success' }} btn-sm">{{ $family->is_active ? translate('Deactivate') : translate('Activate') }}</button></form>
                </td>
            </tr>
            @foreach($family->children as $subFamily)
                <tr>
                    <td class="pl-5">&rsaquo; {{ $subFamily->name }}</td><td>{{ $subFamily->code ?: '-' }}</td><td>{{ translate('Sub Family') }}</td>
                    <td>{{ $subFamily->subFamilyProducts()->count() }}</td><td><span class="badge badge-{{ $subFamily->is_active ? 'success' : 'secondary' }}">{{ $subFamily->is_active ? translate('Active') : translate('Inactive') }}</span></td>
                    <td class="text-right">
                        <a class="btn btn-soft-secondary btn-sm" href="{{ route('operations.inventory.families.edit', $subFamily) }}">{{ translate('Edit') }}</a>
                        <form class="d-inline" method="POST" action="{{ route('operations.inventory.families.toggle', $subFamily) }}">@csrf @method('PATCH')<button class="btn btn-soft-{{ $subFamily->is_active ? 'danger' : 'success' }} btn-sm">{{ $subFamily->is_active ? translate('Deactivate') : translate('Activate') }}</button></form>
                    </td>
                </tr>
            @endforeach
        @empty
            <tr><td colspan="6" class="text-center text-muted">{{ translate('No product families found.') }}</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div></div>
@endsection
