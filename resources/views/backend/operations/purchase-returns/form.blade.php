@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3"><h5 class="mb-0 h6">{{ translate('Create Purchase Return') }}</h5></div>
<div class="card"><div class="card-body">
    <form method="GET" class="row gutters-10 align-items-end mb-3"><div class="col-md-9"><label>{{ translate('Received Purchase Order') }}</label><select class="form-control" name="purchase_order_id" required><option value="">{{ translate('Select purchase order') }}</option>@foreach($purchaseOrders as $order)<option value="{{ $order->id }}" @selected($purchaseOrder?->id === $order->id)>{{ $order->purchase_number }} · {{ $order->supplier?->name }}</option>@endforeach</select></div><div class="col-md-3"><button class="btn btn-soft-primary btn-block">{{ translate('Load Items') }}</button></div></form>

    @if($purchaseOrder)
    @if($errors->has('items'))<div class="alert alert-danger">{{ $errors->first('items') }}</div>@endif
    <form method="POST" action="{{ route('operations.purchase-returns.store') }}">@csrf
        <input type="hidden" name="purchase_order_id" value="{{ $purchaseOrder->id }}">
        <div class="row"><div class="col-md-4 form-group"><label>{{ translate('Supplier') }}</label><input class="form-control" value="{{ $purchaseOrder->supplier?->name }}" disabled></div><div class="col-md-4 form-group"><label>{{ translate('Return Date') }}</label><input class="form-control" type="date" name="return_date" value="{{ old('return_date', now()->toDateString()) }}" required></div><div class="col-md-4 form-group"><label>{{ translate('Reason') }}</label><input class="form-control" name="reason" value="{{ old('reason') }}"></div></div>
        <div class="table-responsive"><table class="table table-bordered"><thead><tr><th>{{ translate('Product') }}</th><th>{{ translate('SKU / Barcode') }}</th><th>{{ translate('Received') }}</th><th>{{ translate('Already Allocated') }}</th><th>{{ translate('Available') }}</th><th>{{ translate('Unit Cost') }}</th><th>{{ translate('Return Quantity') }}</th></tr></thead><tbody>
        @foreach($purchaseOrder->items as $item)
            @php($allocated = $item->purchaseReturnItems->filter(fn ($returnItem) => $returnItem->purchaseReturn?->status !== 'cancelled')->sum('quantity'))
            @php($available = max(0, (float) $item->quantity_received - (float) $allocated))
            <tr><td>{{ $item->product?->name ?: '#'.$item->product_id }}</td><td>{{ $item->productStock?->sku ?: '-' }} / {{ $item->productStock?->barcode ?: '-' }}</td><td>{{ coremarket_quantity($item->quantity_received) }}</td><td>{{ coremarket_quantity($allocated) }}</td><td>{{ coremarket_quantity($available) }}</td><td>{{ coremarket_money($item->unit_cost, $purchaseOrder->currency) }}</td><td><input type="hidden" name="items[{{ $loop->index }}][purchase_order_item_id]" value="{{ $item->id }}"><input class="form-control" type="number" step="0.000001" min="0" max="{{ $available }}" name="items[{{ $loop->index }}][quantity]" value="{{ old('items.'.$loop->index.'.quantity', 0) }}" @disabled($available <= 0)></td></tr>
        @endforeach
        </tbody></table></div>
        <div class="form-group"><label>{{ translate('Notes') }}</label><textarea class="form-control" name="notes">{{ old('notes') }}</textarea></div>
        <button class="btn btn-primary">{{ translate('Create Draft') }}</button>
    </form>
    @endif
</div></div>
@endsection
