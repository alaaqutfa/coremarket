@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3 d-flex justify-content-between align-items-center">
    <div><h5 class="mb-0 h6">{{ $stockCount->reference_no }}</h5><small class="text-muted">{{ translate(ucwords(str_replace('_', ' ', $stockCount->status))) }}</small></div>
    <a class="btn btn-soft-primary" href="{{ route('operations.inventory.stock-counts.index') }}">{{ translate('Back to Stock Counts') }}</a>
</div>
@error('inventory_document')<div class="alert alert-danger">{{ $message }}</div>@enderror
<div class="card"><div class="card-body">
    <div class="table-responsive"><table class="table table-bordered">
        <thead><tr><th>{{ translate('Product') }}</th><th>{{ translate('SKU') }}</th><th>{{ translate('Expected') }}</th><th>{{ translate('Counted') }}</th><th>{{ translate('Variance') }}</th></tr></thead>
        <tbody>@foreach($stockCount->items as $item)<tr><td>{{ $item->product_name_snapshot }}</td><td>{{ $item->sku_snapshot ?: '-' }}</td><td>{{ coremarket_quantity($item->expected_quantity) }}</td><td>{{ coremarket_quantity($item->counted_quantity) }}</td><td>{{ coremarket_quantity($item->variance_quantity) }}</td></tr>@endforeach</tbody>
    </table></div>
    <div class="d-flex flex-wrap" style="gap: 8px;">
        @if($stockCount->status === 'draft' && (auth()->user()?->user_type === 'admin' || auth()->user()?->can('inventory.stock_counts.create')))
            <form method="POST" action="{{ route('operations.inventory.stock-counts.submit', $stockCount) }}">@csrf<button class="btn btn-primary">{{ translate('Submit for Approval') }}</button></form>
        @endif
        @if($stockCount->status === 'pending_approval' && (auth()->user()?->user_type === 'admin' || auth()->user()?->can('inventory.stock_counts.approve')))
            <form method="POST" action="{{ route('operations.inventory.stock-counts.approve', $stockCount) }}">@csrf<button class="btn btn-success">{{ translate('Approve') }}</button></form>
        @endif
        @if($stockCount->status === 'approved' && (auth()->user()?->user_type === 'admin' || auth()->user()?->can('inventory.stock_counts.post')))
            <form method="POST" action="{{ route('operations.inventory.stock-counts.post', $stockCount) }}">@csrf<button class="btn btn-success">{{ translate('Post Variance') }}</button></form>
        @endif
        @if(in_array($stockCount->status, ['draft', 'pending_approval', 'approved']) && (auth()->user()?->user_type === 'admin' || auth()->user()?->can('inventory.stock_counts.cancel')))
            <form method="POST" action="{{ route('operations.inventory.stock-counts.cancel', $stockCount) }}">@csrf<button class="btn btn-soft-dark">{{ translate('Cancel') }}</button></form>
        @endif
    </div>
</div></div>
@endsection
