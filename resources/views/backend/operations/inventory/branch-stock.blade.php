@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3"><h5 class="mb-0 h6">{{ translate('Branch Stock') }}</h5></div>
<div class="card"><div class="card-body">
    <form class="row gutters-10 mb-3">
        <div class="col-md-4"><label>{{ translate('Branch') }}</label><select class="form-control" name="branch_id">@foreach($branches as $option)<option value="{{ $option->id }}" @selected($option->id === $branch->id)>{{ $option->name }}</option>@endforeach</select></div>
        <div class="col-md-6"><label>{{ translate('Product / SKU / Barcode') }}</label><input class="form-control" name="q" value="{{ request('q') }}"></div>
        <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary btn-block">{{ translate('Filter') }}</button></div>
    </form>
    <div class="alert alert-info">{{ translate('Branch balances are the availability source. Aggregate stock remains a compatibility mirror.') }}</div>
    <div class="table-responsive"><table class="table table-bordered">
        <thead><tr><th>{{ translate('Product') }}</th><th>{{ translate('SKU / Barcode') }}</th><th>{{ translate('Branch Quantity') }}</th><th>{{ translate('Reserved') }}</th><th>{{ translate('Available') }}</th><th>{{ translate('Aggregate Quantity') }}</th><th>{{ translate('Last Movement') }}</th></tr></thead>
        <tbody>@forelse($balances as $balance)<tr>
            <td>{{ $balance->product?->name ?: '#'.$balance->product_id }}</td>
            <td>{{ $balance->productStock?->sku ?: '-' }} / {{ $balance->productStock?->barcode ?: '-' }}</td>
            <td>{{ coremarket_quantity($balance->quantity) }}</td>
            <td>{{ coremarket_quantity($balance->reserved_quantity) }}</td>
            <td>{{ coremarket_quantity((float)$balance->quantity - (float)$balance->reserved_quantity) }}</td>
            <td>{{ coremarket_quantity($balance->productStock?->qty ?? 0) }}</td>
            <td>{{ $balance->last_movement_at?->format('Y-m-d H:i') ?: '-' }}</td>
        </tr>@empty<tr><td colspan="7" class="text-center text-muted">{{ translate('No branch balances have been initialized.') }}</td></tr>@endforelse</tbody>
    </table></div>
    {{ $balances->links() }}
</div></div>
@endsection
