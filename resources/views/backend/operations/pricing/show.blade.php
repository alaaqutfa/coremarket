@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3"><div class="row align-items-center"><div class="col"><h5 class="mb-0 h6">{{ $priceList->name }} <small class="text-muted">{{ $priceList->code }}</small></h5></div><div class="col text-right"><a class="btn btn-soft-primary" href="{{ route('operations.price-lists.edit', $priceList) }}">{{ translate('Edit List') }}</a></div></div></div>
<div class="row">
    <div class="col-lg-8">
        <div class="card"><div class="card-header"><h6 class="mb-0">{{ translate('Product Prices') }}</h6></div><div class="card-body">
            <form method="POST" action="{{ route('operations.price-lists.items.store', $priceList) }}">@csrf
                <div class="row">
                    <div class="form-group col-md-6"><label>{{ translate('Product') }}</label><select class="form-control aiz-selectpicker" data-live-search="true" name="product_id" required><option value="">{{ translate('Select product') }}</option>@foreach($products as $product)<option value="{{ $product->id }}">{{ $product->name }}</option>@endforeach</select></div>
                    <div class="form-group col-md-6"><label>{{ translate('Variant (optional)') }}</label><select class="form-control aiz-selectpicker" data-live-search="true" name="product_stock_id"><option value="">{{ translate('All variants') }}</option>@foreach($products as $product)@foreach($product->stocks as $stock)<option value="{{ $stock->id }}">{{ $product->name }} - {{ $stock->variant ?: $stock->sku }}</option>@endforeach @endforeach</select></div>
                    <div class="form-group col-md-4"><label>{{ translate('Fixed Price') }}</label><input type="number" min="0" step="0.01" class="form-control" name="fixed_price"></div>
                    <div class="form-group col-md-4"><label>{{ translate('Margin Percent') }}</label><input type="number" min="0" step="0.01" class="form-control" name="margin_percent"></div>
                    <div class="form-group col-md-4"><label>{{ translate('Discount Percent') }}</label><input type="number" min="0" max="100" step="0.01" class="form-control" name="discount_percent"></div>
                    <div class="form-group col-md-4"><label>{{ translate('Starts At') }}</label><input type="datetime-local" class="form-control" name="starts_at"></div>
                    <div class="form-group col-md-4"><label>{{ translate('Ends At') }}</label><input type="datetime-local" class="form-control" name="ends_at"></div>
                    <div class="form-group col-md-4 pt-4"><label><input type="checkbox" name="is_active" value="1" checked> {{ translate('Active') }}</label></div>
                </div>
                <button class="btn btn-primary">{{ translate('Save Product Price') }}</button>
            </form>
            <hr>
            <div class="table-responsive"><table class="table aiz-table"><thead><tr><th>{{ translate('Product') }}</th><th>{{ translate('Variant') }}</th><th>{{ translate('Price Rule') }}</th><th>{{ translate('Schedule') }}</th><th></th></tr></thead><tbody>
                @forelse($priceList->items as $item)<tr>
                    <td>{{ $item->product?->name ?: '-' }}</td><td>{{ $item->productStock?->variant ?: translate('All variants') }}</td>
                    <td>@if($priceList->pricing_method === 'fixed_price'){{ coremarket_money($item->fixed_price, $priceList->currency) }}@elseif($priceList->pricing_method === 'margin_over_cost'){{ coremarket_number($item->margin_percent ?? $priceList->margin_percent) }}% {{ translate('over cost') }}@else{{ coremarket_number($item->discount_percent ?? $priceList->discount_percent) }}% {{ translate('off regular') }}@endif</td>
                    <td>{{ $item->starts_at?->format('Y-m-d H:i') ?: '-' }} / {{ $item->ends_at?->format('Y-m-d H:i') ?: '-' }}</td>
                    <td><form method="POST" action="{{ route('operations.price-lists.items.destroy', [$priceList, $item]) }}">@csrf @method('DELETE')<button class="btn btn-soft-danger btn-sm">{{ translate('Delete') }}</button></form></td>
                </tr>@empty<tr><td colspan="5" class="text-center text-muted">{{ translate('No product prices configured.') }}</td></tr>@endforelse
            </tbody></table></div>
        </div></div>
    </div>
    <div class="col-lg-4">
        <div class="card"><div class="card-header"><h6 class="mb-0">{{ translate('Assign Customer') }}</h6></div><div class="card-body">
            <form method="POST" action="{{ route('operations.price-lists.customers.assign', $priceList) }}">@csrf
                <select class="form-control aiz-selectpicker mb-3" data-live-search="true" name="customer_id" required><option value="">{{ translate('Select customer') }}</option>@foreach($customers as $customer)<option value="{{ $customer->id }}">{{ $customer->name }} - {{ $customer->email }}{{ (int) $customer->price_list_id === (int) $priceList->id ? ' (' . translate('Assigned') . ')' : '' }}</option>@endforeach</select>
                <button class="btn btn-primary btn-block">{{ translate('Assign Price List') }}</button>
            </form>
        </div></div>
    </div>
</div>
@endsection
