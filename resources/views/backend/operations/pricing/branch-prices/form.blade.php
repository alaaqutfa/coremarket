@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3"><h5 class="mb-0">{{ $branchPrice->exists ? translate('Edit Branch Price') : translate('Add Branch Price') }}</h5></div>
<div class="card"><div class="card-body">
    <form method="POST" action="{{ $branchPrice->exists ? route('operations.branch-prices.update', $branchPrice) : route('operations.branch-prices.store') }}">
        @csrf
        @if($branchPrice->exists) @method('PUT') @endif
        <div class="form-group"><label>{{ translate('Branch') }}</label><select name="store_branch_id" class="form-control" required>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((int) old('store_branch_id', $branchPrice->store_branch_id) === $branch->id)>{{ $branch->name }}</option>@endforeach</select></div>
        <div class="form-group"><label>{{ translate('Product / Variant') }}</label><select name="product_stock_id" class="form-control" required>
            @foreach($products as $product)
                @foreach($product->stocks as $stock)
                    <option value="{{ $stock->id }}" data-product="{{ $product->id }}" @selected((int) old('product_stock_id', $branchPrice->product_stock_id) === $stock->id)>{{ $product->name }} — {{ $stock->variant ?: translate('Default') }} — {{ $stock->sku ?: $stock->barcode }} — {{ coremarket_price($stock->price) }}</option>
                @endforeach
            @endforeach
        </select><input type="hidden" name="product_id" id="branch-price-product-id" value="{{ old('product_id', $branchPrice->product_id) }}"></div>
        <div class="row">
            <div class="col-md-4"><div class="form-group"><label>{{ translate('Branch Price') }}</label><input type="number" step="0.01" min="0" name="price" value="{{ old('price', $branchPrice->price) }}" class="form-control" required></div></div>
            <div class="col-md-4"><div class="form-group"><label>{{ translate('Compare At Price') }}</label><input type="number" step="0.01" min="0" name="compare_at_price" value="{{ old('compare_at_price', $branchPrice->compare_at_price) }}" class="form-control"></div></div>
            <div class="col-md-4"><div class="form-group"><label>{{ translate('Margin %') }}</label><input type="number" step="0.01" name="margin_percent" value="{{ old('margin_percent', $branchPrice->margin_percent) }}" class="form-control"></div></div>
        </div>
        <div class="row">
            <div class="col-md-5"><div class="form-group"><label>{{ translate('Starts At') }}</label><input type="datetime-local" name="starts_at" value="{{ old('starts_at', optional($branchPrice->starts_at)->format('Y-m-d\\TH:i')) }}" class="form-control"></div></div>
            <div class="col-md-5"><div class="form-group"><label>{{ translate('Ends At') }}</label><input type="datetime-local" name="ends_at" value="{{ old('ends_at', optional($branchPrice->ends_at)->format('Y-m-d\\TH:i')) }}" class="form-control"></div></div>
            <div class="col-md-2"><div class="form-group"><label class="d-block">{{ translate('Active') }}</label><input type="hidden" name="is_active" value="0"><label class="aiz-switch aiz-switch-success"><input type="checkbox" name="is_active" value="1" @checked((bool) old('is_active', $branchPrice->exists ? $branchPrice->is_active : true))><span class="slider round"></span></label></div></div>
        </div>
        <p class="text-muted">{{ translate('If no active branch price exists, the resolver falls back to the customer Price List, temporary Sale price, or public price according to policy.') }}</p>
        <button class="btn btn-primary">{{ translate('Save') }}</button>
        <a href="{{ route('operations.branch-prices.index') }}" class="btn btn-light">{{ translate('Cancel') }}</a>
    </form>
</div></div>
@endsection

@section('script')
<script>
    (function () {
        var stock = document.querySelector('[name="product_stock_id"]');
        var product = document.getElementById('branch-price-product-id');
        function syncProduct() {
            var option = stock.options[stock.selectedIndex];
            product.value = option ? option.dataset.product : '';
        }
        stock.addEventListener('change', syncProduct);
        syncProduct();
    })();
</script>
@endsection
