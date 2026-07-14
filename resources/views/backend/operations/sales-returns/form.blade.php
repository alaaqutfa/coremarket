@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3"><h5 class="mb-0 h6">{{ translate('Create Sales Return') }}</h5></div>
<div class="card"><div class="card-body">
    <form method="GET" class="mb-3"><label>{{ translate('Order') }}</label><select class="form-control" name="order_id" onchange="this.form.submit()"><option value="">{{ translate('Select order') }}</option>@foreach($orders as $item)<option value="{{ $item->id }}" @selected($order?->id === $item->id)>#{{ $item->id }} {{ $item->code }}</option>@endforeach</select></form>
    @if($order)
    <div class="alert alert-info">{{ translate('This records stock and cost reversal only. A financial refund or credit note is not created.') }}</div>
    <form method="POST" action="{{ route('operations.sales-returns.store') }}">@csrf
        <input type="hidden" name="order_id" value="{{ $order->id }}">
        <div class="table-responsive"><table class="table table-bordered"><thead><tr><th>{{ translate('Product') }}</th><th>{{ translate('Variant / SKU / Barcode') }}</th><th>{{ translate('Sold') }}</th><th>{{ translate('Previously Returned') }}</th><th>{{ translate('Remaining') }}</th><th>{{ translate('Unit Price') }}</th><th>{{ translate('Cost / Profit') }}</th><th>{{ translate('Return quantity') }}</th><th>{{ translate('Reason') }}</th></tr></thead><tbody>
        @foreach($returnableRows as $row)@php($detail = $row['detail'])@php($stock = $row['product_stock'])<tr><td>{{ $detail->product?->name ?: '#'.$detail->product_id }}</td><td>{{ $detail->variation ?: '-' }}<br><small>{{ $stock?->sku ?: '-' }} / {{ $stock?->barcode ?: $detail->product?->barcode ?: '-' }}</small></td><td>{{ $detail->quantity }}</td><td>{{ $row['returned_quantity'] }}</td><td>{{ $row['remaining_quantity'] }}</td><td>{{ $row['unit_price'] }}</td><td>@if($row['unknown_cost'])<span class="text-warning">{{ translate('Cost snapshot unavailable') }}</span>@else{{ $detail->cost_price }} / {{ $detail->profit_amount }}@endif</td><td><input class="form-control" type="number" step="0.000001" min="0" max="{{ $row['remaining_quantity'] }}" name="items[{{ $loop->index }}][quantity]" value="0" @disabled($row['remaining_quantity'] <= 0)><input type="hidden" name="items[{{ $loop->index }}][order_detail_id]" value="{{ $detail->id }}"></td><td><input class="form-control" name="items[{{ $loop->index }}][reason]" @disabled($row['remaining_quantity'] <= 0)></td></tr>@endforeach
        </tbody></table></div>
        <div class="form-group"><label>{{ translate('Return reason') }}</label><input class="form-control" name="reason" value="{{ old('reason') }}"></div><div class="form-group"><label>{{ translate('Notes') }}</label><textarea class="form-control" name="notes">{{ old('notes') }}</textarea></div><button class="btn btn-primary">{{ translate('Create Return') }}</button>
    </form>
    @endif
</div></div>
@endsection
