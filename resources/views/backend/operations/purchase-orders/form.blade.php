@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3"><h5 class="mb-0 h6">{{ translate('Create Purchase Order') }}</h5></div>
<div class="card"><div class="card-body"><form method="POST" action="{{ route('operations.purchase-orders.store') }}">@csrf
    <div class="row">
        <div class="col-md-4 form-group"><label>{{ translate('Supplier') }}</label><select class="form-control" name="supplier_id"><option value="">{{ translate('Select supplier') }}</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected(old('supplier_id') == $supplier->id)>{{ $supplier->name }}</option>@endforeach</select></div>
        <div class="col-md-4 form-group"><label>{{ translate('Order date') }}</label><input class="form-control" type="date" name="ordered_at" value="{{ old('ordered_at', now()->toDateString()) }}"></div>
        <div class="col-md-4 form-group"><label>{{ translate('Currency') }}</label><input class="form-control" name="currency" value="{{ old('currency', 'USD') }}"></div>
    </div>
    <div class="table-responsive"><table class="table table-bordered"><thead><tr><th>{{ translate('Product') }}</th><th>{{ translate('Variant / Stock') }}</th><th>{{ translate('Quantity') }}</th><th>{{ translate('Unit Cost') }}</th><th>{{ translate('Tax') }}</th><th>{{ translate('Discount') }}</th><th></th></tr></thead><tbody id="purchase-order-items"></tbody></table></div>
    <button type="button" id="add-purchase-item" class="btn btn-soft-primary btn-sm mb-3">{{ translate('Add item') }}</button>
    <div class="form-group"><label>{{ translate('Notes') }}</label><textarea class="form-control" name="notes">{{ old('notes') }}</textarea></div>
    <div class="text-right"><button class="btn btn-primary">{{ translate('Create') }}</button></div>
</form></div></div>

<template id="purchase-item-template"><tr>
    <td><select class="form-control product-select" name="items[__INDEX__][product_id]" required><option value="">{{ translate('Select product') }}</option>@foreach($products as $product)<option value="{{ $product->id }}">{{ $product->name }}</option>@endforeach</select></td>
    <td><select class="form-control stock-select" name="items[__INDEX__][product_stock_id]"><option value="" data-product-id="">{{ translate('Default product stock') }}</option>@foreach($productStocks as $stock)<option value="{{ $stock->id }}" data-product-id="{{ $stock->product_id }}">{{ $stock->variant ?: translate('Default') }} | {{ $stock->sku ?: '-' }} | {{ $stock->barcode ?: '-' }} | {{ translate('Qty') }}: {{ $stock->qty }}</option>@endforeach</select></td>
    <td><input class="form-control" type="number" step="0.000001" min="0.000001" name="items[__INDEX__][quantity_ordered]" required></td>
    <td><input class="form-control" type="number" step="0.000001" min="0" name="items[__INDEX__][unit_cost]"></td>
    <td><input class="form-control" type="number" step="0.000001" min="0" value="0" name="items[__INDEX__][tax_amount]"></td>
    <td><input class="form-control" type="number" step="0.000001" min="0" value="0" name="items[__INDEX__][discount_amount]"></td>
    <td><button type="button" class="btn btn-soft-danger btn-sm remove-purchase-item">{{ translate('Remove') }}</button></td>
</tr></template>
@endsection

@section('script')
<script>
    (function () {
        const rows = document.getElementById('purchase-order-items');
        const template = document.getElementById('purchase-item-template').innerHTML;
        let index = 0;
        function addRow() {
            rows.insertAdjacentHTML('beforeend', template.replaceAll('__INDEX__', index++));
            const row = rows.lastElementChild;
            const product = row.querySelector('.product-select');
            const stock = row.querySelector('.stock-select');
            product.addEventListener('change', function () {
                Array.from(stock.options).forEach(option => option.hidden = option.dataset.productId && option.dataset.productId !== this.value);
                stock.value = '';
            });
            row.querySelector('.remove-purchase-item').addEventListener('click', () => { if (rows.children.length > 1) row.remove(); });
        }
        document.getElementById('add-purchase-item').addEventListener('click', addRow);
        addRow();
    })();
</script>
@endsection
