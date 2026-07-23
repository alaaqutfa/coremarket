@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3"><h5 class="mb-0 h6">{{ translate('Stock / Variants') }}</h5></div>
<div class="card"><div class="card-body">
    <form class="row gutters-5 mb-3">
        <div class="col-md-3"><input class="form-control" name="search" value="{{ request('search') }}" placeholder="{{ translate('Product, SKU, or barcode') }}"></div>
        <div class="col-md-2"><select class="form-control" name="status"><option value="">{{ translate('All statuses') }}</option>@foreach(['ok','low_stock','out_of_stock','mismatch'] as $status)<option value="{{ $status }}" @selected(request('status')===$status)>{{ translate($status) }}</option>@endforeach</select></div>
        <div class="col-md-2"><select class="form-control" name="product_family_id"><option value="">{{ translate('All families') }}</option>@foreach($families as $family)<option value="{{ $family->id }}" @selected((string) request('product_family_id') === (string) $family->id)>{{ $family->name }}</option>@endforeach</select></div>
        <div class="col-md-2"><select class="form-control" name="product_sub_family_id"><option value="">{{ translate('All sub families') }}</option>@foreach($families as $family)@foreach($family->children as $subFamily)<option value="{{ $subFamily->id }}" @selected((string) request('product_sub_family_id') === (string) $subFamily->id)>{{ $family->name }} / {{ $subFamily->name }}</option>@endforeach @endforeach</select></div>
        <div class="col-md-2"><label><input type="checkbox" name="low_stock_only" value="1" @checked(request('low_stock_only'))> {{ translate('Low stock only') }}</label></div>
        <div class="col-md-1"><button class="btn btn-primary">{{ translate('Filter') }}</button></div>
    </form>
    <div class="table-responsive"><table class="table aiz-table">
        <thead><tr><th>{{ translate('Product') }}</th><th>{{ translate('Family') }}</th><th>{{ translate('Variant') }}</th><th>SKU</th><th>{{ translate('Product barcode') }}</th><th>{{ translate('Variant barcode') }}</th><th>Qty</th><th>{{ translate('Current stock') }}</th><th>{{ translate('Status') }}</th><th>{{ translate('Last movement') }}</th><th></th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr>
                <td>{{ $row['product']?->name }}</td>
                <td>{{ $row['product']?->productFamily?->name ?: '-' }}@if($row['product']?->productSubFamily)<small class="d-block text-muted">{{ $row['product']->productSubFamily->name }}</small>@endif</td>
                <td>{{ $row['stock']->variant }}</td><td>{{ $row['stock']->sku }}</td><td>{{ $row['product']?->barcode }}</td><td>{{ $row['stock']->barcode }}</td>
                <td>{{ coremarket_quantity($row['stock']->qty) }}</td><td>{{ coremarket_quantity($row['product']?->current_stock) }}</td>
                <td><span class="badge badge-{{ $row['status']==='ok'?'success':($row['status']==='out_of_stock'?'danger':'warning') }}">{{ translate($row['status']) }}</span></td>
                <td>{{ $row['movementAt'] }}</td>
                <td>@can('inventory.stock.adjust')<a href="{{ route('operations.inventory.stock.adjust',$row['stock']) }}" class="btn btn-soft-primary btn-sm">{{ translate('Adjust') }}</a>@endcan</td>
            </tr>
        @empty
            <tr><td colspan="11" class="text-center">{{ translate('No data found') }}</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div></div>
@endsection
