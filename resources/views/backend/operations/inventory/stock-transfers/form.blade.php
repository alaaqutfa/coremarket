@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3"><h5 class="mb-0 h6">{{ translate('Create Stock Transfer') }}</h5></div>
<div class="card"><div class="card-body">
    @if($errors->has('stock_transfer'))<div class="alert alert-danger">{{ $errors->first('stock_transfer') }}</div>@endif
    <form method="POST" action="{{ route('operations.inventory.stock-transfers.store') }}">@csrf
        <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">
        <div class="row"><div class="col-md-6 form-group"><label>{{ translate('From Branch') }}</label><select class="form-control" name="from_branch_id" required>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></div><div class="col-md-6 form-group"><label>{{ translate('To Branch') }}</label><select class="form-control" name="to_branch_id" required>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></div></div>
        <div class="form-group"><label>{{ translate('Product / Variant') }}</label><select class="form-control aiz-selectpicker" data-live-search="true" name="product_stock_id" required>@foreach($stocks as $stock)<option value="{{ $stock->id }}">{{ $stock->product?->name }} · {{ $stock->variant ?: translate('Default') }} · {{ $stock->sku ?: '-' }} / {{ $stock->barcode ?: '-' }}</option>@endforeach</select></div>
        <div class="form-group"><label>{{ translate('Quantity') }}</label><input class="form-control" type="number" step="0.000001" min="0.000001" name="quantity" value="{{ old('quantity') }}" required></div>
        <div class="form-group"><label>{{ translate('Notes') }}</label><textarea class="form-control" name="notes">{{ old('notes') }}</textarea></div>
        <button class="btn btn-primary">{{ translate('Create Draft') }}</button>
    </form>
</div></div>
@endsection
