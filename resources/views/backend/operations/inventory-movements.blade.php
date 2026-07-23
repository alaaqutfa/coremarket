@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3"><h5 class="mb-0 h6">{{ translate('Inventory Movements') }}</h5></div>
<div class="card"><div class="card-body">
    <form class="row gutters-5 mb-3">
        <div class="col-md-2"><input class="form-control" name="from" type="date" value="{{ request('from') }}"></div>
        <div class="col-md-2"><input class="form-control" name="to" type="date" value="{{ request('to') }}"></div>
        <div class="col-md-2"><select class="form-control" name="movement_type"><option value="">{{ translate('All types') }}</option>@foreach(['sale','sale_reversal','purchase','adjustment','reservation','release'] as $type)<option value="{{ $type }}" @selected(request('movement_type')===$type)>{{ $type }}</option>@endforeach</select></div>
        <div class="col-md-2"><select class="form-control" name="product_family_id"><option value="">{{ translate('All families') }}</option>@foreach($families as $family)<option value="{{ $family->id }}" @selected((string) request('product_family_id') === (string) $family->id)>{{ $family->name }}</option>@endforeach</select></div>
        <div class="col-md-3"><select class="form-control" name="product_sub_family_id"><option value="">{{ translate('All sub families') }}</option>@foreach($families as $family)@foreach($family->children as $subFamily)<option value="{{ $subFamily->id }}" @selected((string) request('product_sub_family_id') === (string) $subFamily->id)>{{ $family->name }} / {{ $subFamily->name }}</option>@endforeach @endforeach</select></div>
        <div class="col-md-1"><button class="btn btn-primary">{{ translate('Filter') }}</button></div>
    </form>
    <div class="table-responsive"><table class="table aiz-table">
        <thead><tr><th>{{ translate('Date') }}</th><th>{{ translate('Type') }}</th><th>{{ translate('Product') }}</th><th>{{ translate('Family') }}</th><th>{{ translate('Variant / SKU / Barcode') }}</th><th>{{ translate('Quantity') }}</th><th>{{ translate('Cost') }}</th><th>{{ translate('Reference') }}</th></tr></thead>
        <tbody>
        @forelse($movements as $movement)
            <tr>
                <td>{{ $movement->created_at }}</td><td>{{ $movement->movement_type }} / {{ $movement->direction }}</td><td>{{ $movement->product?->name ?? ('#'.$movement->product_id) }}</td>
                <td>{{ $movement->product?->productFamily?->name ?: '-' }}@if($movement->product?->productSubFamily)<small class="d-block text-muted">{{ $movement->product->productSubFamily->name }}</small>@endif</td>
                <td>{{ $movement->productStock?->variant }} {{ $movement->productStock?->sku }} {{ $movement->productStock?->barcode }}</td>
                <td>{{ coremarket_quantity($movement->quantity) }}</td><td>{{ coremarket_money($movement->unit_cost) }} / {{ coremarket_money($movement->total_cost) }}</td><td>{{ class_basename($movement->reference_type) }} #{{ $movement->reference_id }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="text-center">{{ translate('No data found') }}</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $movements->links() }}
</div></div>
@endsection
