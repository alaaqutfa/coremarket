@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3"><h5 class="mb-0 h6">{{ translate('Create Purchase Order') }}</h5></div>
<div class="card"><div class="card-body"><form method="POST" action="{{ route('operations.purchase-orders.store') }}">@csrf
    <div class="row">
        <div class="col-md-4 form-group"><label>{{ translate('Supplier') }}</label><select class="form-control" name="supplier_id"><option value="">{{ translate('Select supplier') }}</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected(old('supplier_id') == $supplier->id)>{{ $supplier->name }}</option>@endforeach</select></div>
        <div class="col-md-4 form-group"><label>{{ translate('Order date') }}</label><input class="form-control" type="date" name="ordered_at" value="{{ old('ordered_at', now()->toDateString()) }}"></div>
        <div class="col-md-4 form-group"><label>{{ translate('Currency') }}</label><input class="form-control" name="currency" value="{{ old('currency', 'USD') }}"></div>
    </div>

    <div class="form-group">
        <label for="purchase-barcode-input">{{ translate('Barcode / SKU') }}</label>
        <div class="input-group">
            <input id="purchase-barcode-input" class="form-control" autocomplete="off" placeholder="{{ translate('Scan barcode or enter SKU, then press Enter') }}">
            <div class="input-group-append"><button id="purchase-barcode-add" type="button" class="btn btn-primary">{{ translate('Add scanned item') }}</button></div>
        </div>
        <small id="purchase-barcode-status" class="form-text text-muted">{{ translate('The scanner acts as a keyboard. Products must already exist.') }}</small>
    </div>

    @if($errors->has('items'))<div class="alert alert-danger">{{ $errors->first('items') }}</div>@endif
    <div class="table-responsive"><table class="table table-bordered table-sm">
        <thead><tr>
            <th style="min-width:180px">{{ translate('Product') }}</th>
            <th style="min-width:180px">{{ translate('Variant / Stock') }}</th>
            <th style="min-width:90px">{{ translate('Quantity') }}</th>
            <th style="min-width:110px">{{ translate('Cost Price') }}</th>
            <th style="min-width:100px">{{ translate('Margin') }} %</th>
            <th style="min-width:110px">{{ translate('Regular Price') }}</th>
            <th style="min-width:110px">{{ translate('Sale Price') }}</th>
            <th style="min-width:155px">{{ translate('Tax') }}</th>
            <th style="min-width:110px">{{ translate('Subtotal') }}</th>
            <th style="min-width:110px">{{ translate('Line Total') }}</th>
            <th></th>
        </tr></thead>
        <tbody id="purchase-order-items"></tbody>
    </table></div>
    <button type="button" id="add-purchase-item" class="btn btn-soft-primary btn-sm mb-3">{{ translate('Add item') }}</button>
    <div class="form-group"><label>{{ translate('Notes') }}</label><textarea class="form-control" name="notes">{{ old('notes') }}</textarea></div>
    <div class="text-right"><button class="btn btn-primary">{{ translate('Create') }}</button></div>
</form></div></div>

<template id="purchase-item-template"><tr class="purchase-item-row">
    <td><select class="form-control product-select" name="items[__INDEX__][product_id]" required><option value="">{{ translate('Select product') }}</option>@foreach($products as $product)<option value="{{ $product->id }}" data-cost="{{ $product->purchase_price }}" data-regular="{{ $product->unit_price }}">{{ $product->name }}</option>@endforeach</select></td>
    <td><select class="form-control stock-select" name="items[__INDEX__][product_stock_id]"><option value="" data-product-id="">{{ translate('Default product stock') }}</option>@foreach($productStocks as $stock)<option value="{{ $stock->id }}" data-product-id="{{ $stock->product_id }}" data-price="{{ $stock->price }}">{{ $stock->variant ?: translate('Default') }} | {{ $stock->sku ?: '-' }} | {{ $stock->barcode ?: '-' }} | {{ translate('Qty') }}: {{ coremarket_quantity($stock->qty) }}</option>@endforeach</select></td>
    <td><input class="form-control quantity-input" type="number" step="0.000001" min="0.000001" value="1" name="items[__INDEX__][quantity_ordered]" required></td>
    <td><input class="form-control cost-input" type="number" step="0.01" min="0" name="items[__INDEX__][unit_cost]"></td>
    <td><input class="form-control margin-input" type="number" step="0.01" name="items[__INDEX__][margin_percent]"></td>
    <td><input class="form-control regular-input" type="number" step="0.01" min="0" name="items[__INDEX__][regular_price]"></td>
    <td><input class="form-control sale-input" type="number" step="0.01" min="0" name="items[__INDEX__][sale_price]" placeholder="{{ translate('Optional') }}"></td>
    <td>
        <input type="hidden" name="items[__INDEX__][tax_enabled]" value="0">
        <label class="d-flex align-items-center mb-1"><input class="tax-enabled mr-2" type="checkbox" name="items[__INDEX__][tax_enabled]" value="1"> {{ translate('Taxable') }}</label>
        <div class="input-group input-group-sm"><input class="form-control tax-rate" type="number" step="0.0001" min="0" max="100" value="{{ $defaultTaxRate?->rate ?? 0 }}" name="items[__INDEX__][tax_rate]"><div class="input-group-append"><span class="input-group-text">%</span></div></div>
        <input class="tax-amount" type="hidden" value="0" name="items[__INDEX__][tax_amount]">
        <small class="tax-display text-muted">{{ coremarket_money(0, 'USD') }}</small>
    </td>
    <td><span class="line-subtotal">{{ coremarket_money(0, 'USD') }}</span><input type="hidden" name="items[__INDEX__][discount_amount]" value="0"></td>
    <td><strong class="line-total">{{ coremarket_money(0, 'USD') }}</strong></td>
    <td><button type="button" class="btn btn-soft-danger btn-sm remove-purchase-item">{{ translate('Remove') }}</button></td>
</tr></template>
@endsection

@section('script')
<script>
    (function () {
        const rows = document.getElementById('purchase-order-items');
        const template = document.getElementById('purchase-item-template').innerHTML;
        const barcodeInput = document.getElementById('purchase-barcode-input');
        const barcodeStatus = document.getElementById('purchase-barcode-status');
        const lookupUrl = @json(route('operations.purchase-orders.product-lookup'));
        const notFoundMessage = @json('Product not found. Create product first or use manual item entry.');
        let index = 0;

        const money = value => `${(Number(value) || 0).toFixed(2)} USD`;

        function calculateRow(row, changedField) {
            const quantity = Number(row.querySelector('.quantity-input').value) || 0;
            const cost = Number(row.querySelector('.cost-input').value) || 0;
            const marginInput = row.querySelector('.margin-input');
            const regularInput = row.querySelector('.regular-input');

            if (changedField === 'margin' && marginInput.value !== '') {
                regularInput.value = (cost * (1 + (Number(marginInput.value) || 0) / 100)).toFixed(2);
            } else if ((changedField === 'regular' || changedField === 'cost') && cost > 0 && regularInput.value !== '') {
                marginInput.value = (((Number(regularInput.value) - cost) / cost) * 100).toFixed(2);
            }

            const subtotal = cost * quantity;
            const taxable = row.querySelector('.tax-enabled').checked;
            const taxRate = taxable ? (Number(row.querySelector('.tax-rate').value) || 0) : 0;
            const taxAmount = subtotal * taxRate / 100;
            row.querySelector('.tax-amount').value = taxAmount.toFixed(2);
            row.querySelector('.tax-display').textContent = money(taxAmount);
            row.querySelector('.line-subtotal').textContent = money(subtotal);
            row.querySelector('.line-total').textContent = money(subtotal + taxAmount);
        }

        function addRow(initial = {}) {
            rows.insertAdjacentHTML('beforeend', template.replaceAll('__INDEX__', index++));
            const row = rows.lastElementChild;
            const product = row.querySelector('.product-select');
            const stock = row.querySelector('.stock-select');

            product.value = initial.product_id || '';
            Array.from(stock.options).forEach(option => option.hidden = option.dataset.productId && option.dataset.productId !== String(product.value));
            stock.value = initial.product_stock_id || '';
            row.querySelector('.quantity-input').value = initial.quantity || 1;
            row.querySelector('.cost-input').value = initial.cost_price ?? product.selectedOptions[0]?.dataset.cost ?? '';
            row.querySelector('.regular-input').value = initial.regular_price ?? stock.selectedOptions[0]?.dataset.price ?? product.selectedOptions[0]?.dataset.regular ?? '';
            row.querySelector('.sale-input').value = initial.sale_price ?? '';
            row.querySelector('.margin-input').value = initial.margin_percent ?? '';

            product.addEventListener('change', function () {
                Array.from(stock.options).forEach(option => option.hidden = option.dataset.productId && option.dataset.productId !== this.value);
                stock.value = '';
                row.querySelector('.cost-input').value = this.selectedOptions[0]?.dataset.cost || '';
                row.querySelector('.regular-input').value = this.selectedOptions[0]?.dataset.regular || '';
                calculateRow(row, 'cost');
            });
            stock.addEventListener('change', function () {
                const selected = this.selectedOptions[0];
                if (selected?.dataset.price !== undefined) row.querySelector('.regular-input').value = selected.dataset.price;
                calculateRow(row, 'regular');
            });
            row.querySelector('.quantity-input').addEventListener('input', () => calculateRow(row));
            row.querySelector('.cost-input').addEventListener('input', () => calculateRow(row, 'cost'));
            row.querySelector('.margin-input').addEventListener('input', () => calculateRow(row, 'margin'));
            row.querySelector('.regular-input').addEventListener('input', () => calculateRow(row, 'regular'));
            row.querySelector('.tax-enabled').addEventListener('change', () => calculateRow(row));
            row.querySelector('.tax-rate').addEventListener('input', () => calculateRow(row));
            row.querySelector('.remove-purchase-item').addEventListener('click', () => {
                if (rows.children.length > 1) row.remove();
            });
            calculateRow(row, initial.margin_percent !== undefined ? 'margin' : 'regular');
            return row;
        }

        function existingRow(data) {
            return Array.from(rows.querySelectorAll('.purchase-item-row')).find(row => {
                const stockId = row.querySelector('.stock-select').value;
                const productId = row.querySelector('.product-select').value;
                return data.product_stock_id
                    ? stockId === String(data.product_stock_id)
                    : !stockId && productId === String(data.product_id);
            });
        }

        async function addScannedItem() {
            const identity = barcodeInput.value.trim();
            if (!identity) return;
            barcodeStatus.className = 'form-text text-muted';
            barcodeStatus.textContent = @json(translate('Searching...'));

            try {
                const response = await fetch(`${lookupUrl}?q=${encodeURIComponent(identity)}`, {
                    headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}
                });
                const payload = await response.json();
                if (!response.ok || !payload.ok) throw new Error(payload.message || notFoundMessage);

                const duplicate = existingRow(payload.data);
                if (duplicate) {
                    const quantity = duplicate.querySelector('.quantity-input');
                    quantity.value = (Number(quantity.value) || 0) + 1;
                    calculateRow(duplicate);
                } else {
                    addRow(payload.data);
                }
                barcodeStatus.className = 'form-text text-success';
                barcodeStatus.textContent = `${payload.data.name} - ${@json(translate('added'))}`;
                barcodeInput.value = '';
            } catch (error) {
                barcodeStatus.className = 'form-text text-danger';
                barcodeStatus.textContent = error.message || notFoundMessage;
            } finally {
                barcodeInput.focus();
            }
        }

        document.getElementById('add-purchase-item').addEventListener('click', () => addRow());
        document.getElementById('purchase-barcode-add').addEventListener('click', addScannedItem);
        barcodeInput.addEventListener('keydown', event => {
            if (event.key === 'Enter') {
                event.preventDefault();
                addScannedItem();
            }
        });
        addRow();
        barcodeInput.focus();
    })();
</script>
@endsection
