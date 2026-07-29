@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3"><h5 class="mb-0">{{ translate('Branch Prices') }}</h5></div>
<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
        <form class="form-inline" method="GET">
            <select name="branch_id" class="form-control mr-2">
                @foreach($branches as $branch)
                    <option value="{{ $branch->id }}" @selected((int) request('branch_id', $branches->first()?->id) === $branch->id)>{{ $branch->name }}</option>
                @endforeach
            </select>
            <input name="q" value="{{ request('q') }}" class="form-control mr-2" placeholder="{{ translate('Product, SKU or barcode') }}">
            <button class="btn btn-soft-primary">{{ translate('Filter') }}</button>
        </form>
        @if($canManage)
            <a href="{{ route('operations.branch-prices.create') }}" class="btn btn-primary">{{ translate('Add Branch Price') }}</a>
        @endif
    </div>
    <div class="card-body">
        <p class="text-muted">{{ translate('Branch prices add a location-specific selling price. Customer Price Lists and temporary Sale prices remain separate and are resolved by the configured priority.') }}</p>
        <div class="table-responsive">
            <table class="table aiz-table mb-0">
                <thead><tr><th>{{ translate('Branch') }}</th><th>{{ translate('Product') }}</th><th>{{ translate('SKU / Barcode') }}</th><th>{{ translate('Public Price') }}</th><th>{{ translate('Branch Price') }}</th><th>{{ translate('Status') }}</th><th></th></tr></thead>
                <tbody>
                @forelse($prices as $branchPrice)
                    <tr>
                        <td>{{ $branchPrice->branch?->name }}</td>
                        <td>{{ $branchPrice->product?->name }}</td>
                        <td>{{ $branchPrice->productStock?->sku ?: $branchPrice->productStock?->barcode ?: translate('All variants') }}</td>
                        <td>{{ coremarket_price($branchPrice->productStock?->price ?? $branchPrice->product?->unit_price) }}</td>
                        <td>{{ coremarket_price($branchPrice->price) }}</td>
                        <td>{{ $branchPrice->is_active ? translate('Active') : translate('Inactive') }}</td>
                        <td class="text-right">@if($canManage)<a href="{{ route('operations.branch-prices.edit', $branchPrice) }}" class="btn btn-soft-primary btn-sm">{{ translate('Edit') }}</a>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted">{{ translate('No branch prices found. Public pricing remains the fallback.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $prices->links() }}</div>
    </div>
</div>
@endsection
