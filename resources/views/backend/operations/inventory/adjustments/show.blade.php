@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3 d-flex justify-content-between align-items-center">
    <div>
        <h5 class="mb-0 h6">{{ $document->reference_no }}</h5>
        <small class="text-muted">{{ translate(ucwords(str_replace('_', ' ', $document->adjustment_type))) }} | {{ translate(ucwords(str_replace('_', ' ', $document->status))) }}</small>
    </div>
    <a class="btn btn-soft-primary" href="{{ route('operations.inventory.adjustments.index') }}">{{ translate('Back to Adjustments') }}</a>
</div>

@error('inventory_document')<div class="alert alert-danger">{{ $message }}</div>@enderror

<div class="card"><div class="card-body">
    <div class="row mb-3">
        <div class="col-md-3"><strong>{{ translate('Reason') }}:</strong> {{ $document->reason ?: '-' }}</div>
        <div class="col-md-3"><strong>{{ translate('Branch context') }}:</strong> {{ $document->branch?->name ?: translate('Unified inventory') }}</div>
        <div class="col-md-3"><strong>{{ translate('Created by') }}:</strong> {{ $document->creator?->name ?: '-' }}</div>
        <div class="col-md-3"><strong>{{ translate('Posted at') }}:</strong> {{ $document->posted_at?->format('Y-m-d H:i') ?: '-' }}</div>
    </div>
    <div class="table-responsive">
        <table class="table table-bordered">
            <thead><tr><th>{{ translate('Product') }}</th><th>{{ translate('SKU') }}</th><th>{{ translate('Before') }}</th><th>{{ translate('Change') }}</th><th>{{ translate('After') }}</th><th>{{ translate('Unit cost') }}</th></tr></thead>
            <tbody>
            @foreach($document->items as $item)
                <tr>
                    <td>{{ $item->product_name_snapshot }}</td>
                    <td>{{ $item->sku_snapshot ?: '-' }}</td>
                    <td>{{ coremarket_quantity($item->quantity_before) }}</td>
                    <td>{{ coremarket_quantity($item->quantity_change) }}</td>
                    <td>{{ coremarket_quantity($item->quantity_after) }}</td>
                    <td>{{ $item->unit_cost !== null ? coremarket_money($item->unit_cost) : '-' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div class="d-flex flex-wrap" style="gap: 8px;">
        @php
            $createPermission = $document->adjustment_type === 'opening_stock' ? 'inventory.opening_stock.create' : 'inventory.adjustments.create';
            $cancelPermission = $document->adjustment_type === 'opening_stock' ? 'inventory.opening_stock.create' : 'inventory.adjustments.cancel';
        @endphp
        @if($document->status === 'draft' && (auth()->user()?->user_type === 'admin' || auth()->user()?->can($createPermission)))
            <form method="POST" action="{{ route('operations.inventory.adjustments.submit', $document) }}">@csrf<button class="btn btn-primary">{{ translate('Submit for Approval') }}</button></form>
        @endif
        @if($document->status === 'pending_approval' && (auth()->user()?->user_type === 'admin' || auth()->user()?->can('inventory.adjustments.approve')))
            <form method="POST" action="{{ route('operations.inventory.adjustments.approve', $document) }}">@csrf<button class="btn btn-success">{{ translate('Approve') }}</button></form>
            <form method="POST" action="{{ route('operations.inventory.adjustments.reject', $document) }}">@csrf<button class="btn btn-soft-danger">{{ translate('Reject') }}</button></form>
        @endif
        @php
            $postPermission = $document->adjustment_type === 'opening_stock' ? 'inventory.opening_stock.post' : 'inventory.adjustments.post';
        @endphp
        @if($document->status === 'approved' && (auth()->user()?->user_type === 'admin' || auth()->user()?->can($postPermission)))
            <form method="POST" action="{{ route('operations.inventory.adjustments.post', $document) }}">@csrf<button class="btn btn-success">{{ translate('Post Stock Movement') }}</button></form>
        @endif
        @if(in_array($document->status, ['draft', 'pending_approval', 'approved']) && (auth()->user()?->user_type === 'admin' || auth()->user()?->can($cancelPermission)))
            <form method="POST" action="{{ route('operations.inventory.adjustments.cancel', $document) }}">@csrf<button class="btn btn-soft-dark">{{ translate('Cancel') }}</button></form>
        @endif
    </div>
</div></div>
@endsection
