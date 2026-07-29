@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3"><h5 class="mb-0 h6">{{ translate('Create Stock Count') }}</h5></div>
<div class="card"><div class="card-body">
    <form method="POST" action="{{ route('operations.inventory.stock-counts.store') }}">
        @csrf
        <div class="row">
            <div class="col-md-6 form-group">
                <label>{{ translate('Product / Variant') }}</label>
                <select class="form-control aiz-selectpicker" data-live-search="true" name="product_stock_id" required>
                    <option value="">{{ translate('Select product') }}</option>
                    @foreach($stocks as $stock)
                        <option value="{{ $stock->id }}">{{ $stock->product?->name }} {{ $stock->variant ? ' - '.$stock->variant : '' }} | {{ $stock->sku ?: $stock->barcode }} | {{ translate('Expected') }}: {{ coremarket_quantity($stock->qty) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3 form-group"><label>{{ translate('Counted quantity') }}</label><input class="form-control" type="number" step="0.000001" name="counted_quantity" required></div>
            <div class="col-md-3 form-group"><label>{{ translate('Branch context') }}</label><select class="form-control" name="branch_id"><option value="">{{ translate('Default / unified') }}</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></div>
        </div>
        <div class="form-group"><label>{{ translate('Notes') }}</label><textarea class="form-control" name="notes"></textarea></div>
        <div class="alert alert-info">{{ translate('Counting does not change stock. Variance is posted only after approval.') }}</div>
        <button class="btn btn-primary">{{ translate('Create Draft Count') }}</button>
    </form>
</div></div>
@endsection
