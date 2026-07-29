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

<div id="purchase-product-not-found-modal" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="purchase-product-not-found-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 id="purchase-product-not-found-title" class="modal-title">{{ translate('Product not found') }}</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="{{ translate('Close') }}"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                <p>{{ translate('We could not find a product matching this barcode or search term.') }}</p>
                <p class="mb-0 text-muted"><strong>{{ translate('Search') }}:</strong> <span id="purchase-missing-product-query"></span></p>
            </div>
            <div class="modal-footer">
                <button id="purchase-correct-search" type="button" class="btn btn-soft-secondary">{{ translate('Try another barcode') }}</button>
                @if($quickProductAllowed)
                    <button id="purchase-open-quick-product" type="button" class="btn btn-primary">{{ translate('Add new product') }}</button>
                @endif
                <button type="button" class="btn btn-light" data-dismiss="modal">{{ translate('Cancel') }}</button>
            </div>
        </div>
    </div>
</div>

@if($quickProductAllowed)
<div id="purchase-quick-product-modal" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="purchase-quick-product-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <form id="purchase-quick-product-form">
                @csrf
                <div class="modal-header">
                    <div>
                        <h5 id="purchase-quick-product-title" class="modal-title">{{ translate('Quick Product Create') }}</h5>
                        <small class="text-muted">{{ translate('Create a simple product without leaving this purchase order.') }}</small>
                    </div>
                    <button type="button" class="close" data-dismiss="modal" aria-label="{{ translate('Close') }}"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div id="purchase-quick-product-errors" class="alert alert-danger d-none"></div>
                    @if($purchaseBranch)
                        <div class="alert alert-light py-2">{{ translate('Branch context') }}: <strong>{{ $purchaseBranch->name }}</strong>. {{ translate('Stock and pricing remain unified in this step.') }}</div>
                    @endif
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>{{ translate('Product name') }} <span class="text-danger">*</span></label>
                            <input class="form-control" name="name" maxlength="255" required>
                        </div>
                        <div class="col-md-3 form-group">
                            <label>{{ translate('SKU') }}</label>
                            <input class="form-control" name="sku" maxlength="255">
                        </div>
                        <div class="col-md-3 form-group">
                            <label>{{ translate('Barcode') }}</label>
                            <input class="form-control" name="barcode" maxlength="255">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>{{ translate('Product Family') }}</label>
                            <select class="form-control" name="product_family_id" id="quick-product-family">
                                <option value="">{{ translate('None') }}</option>
                                @foreach($quickProductFamilies as $family)<option value="{{ $family->id }}">{{ $family->name }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-md-4 form-group">
                            <label>{{ translate('Sub Family') }}</label>
                            <select class="form-control" name="product_sub_family_id" id="quick-product-sub-family">
                                <option value="">{{ translate('None') }}</option>
                                @foreach($quickProductFamilies as $family)
                                    @foreach($family->children as $subFamily)<option value="{{ $subFamily->id }}" data-family-id="{{ $family->id }}">{{ $subFamily->name }}</option>@endforeach
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4 form-group">
                            <label>{{ translate('Brand') }}</label>
                            <select class="form-control" name="brand_id">
                                <option value="">{{ translate('None') }}</option>
                                @foreach($quickProductBrands as $brand)<option value="{{ $brand->id }}">{{ $brand->name }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-md-2 form-group">
                            <label>{{ translate('Unit') }}</label>
                            <input class="form-control" name="unit" value="pc" maxlength="50">
                        </div>
                        <div class="col-md-2 form-group">
                            <label>{{ translate('Cost Price') }} <span class="text-danger">*</span></label>
                            <input class="form-control quick-cost-price" type="number" name="cost_price" min="0" step="0.01" required>
                        </div>
                        <div class="col-md-2 form-group">
                            <label>{{ translate('Margin') }} %</label>
                            <input class="form-control quick-margin-percent" type="number" name="margin_percent" step="0.01">
                        </div>
                        <div class="col-md-3 form-group">
                            <label>{{ translate('Regular Price') }} <span class="text-danger">*</span></label>
                            <input class="form-control quick-regular-price" type="number" name="regular_price" min="0.01" step="0.01">
                        </div>
                        <div class="col-md-3 form-group">
                            <label>{{ translate('Sale Price') }}</label>
                            <input class="form-control quick-sale-price" type="number" name="sale_price" min="0" step="0.01" placeholder="{{ translate('Optional') }}">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>{{ translate('Opening Stock') }}</label>
                            <input class="form-control" type="number" name="opening_stock" min="0" step="0.000001" value="0" readonly>
                            <small class="text-muted">{{ translate('Products start at zero. Receive the purchase or create an Opening Stock document after saving.') }}</small>
                        </div>
                        <div class="col-md-4 form-group">
                            <label class="d-block">{{ translate('Tax') }}</label>
                            <input type="hidden" name="tax_enabled" value="0">
                            <label><input type="checkbox" name="tax_enabled" value="1"> {{ translate('Taxable') }}</label>
                        </div>
                        <div class="col-md-4 form-group">
                            <label>{{ translate('Tax Rate') }} %</label>
                            <input class="form-control" type="number" name="tax_rate" min="0" max="100" step="0.0001" value="{{ $defaultTaxRate?->rate ?? 0 }}">
                        </div>
                    </div>
                    @if($priceListsEnabled)
                        <div class="alert alert-info py-2">{{ translate('Customer price lists can be configured later from Pricing > Price Lists. They are not required here.') }}</div>
                    @endif
                    <button class="btn btn-link px-0" type="button" data-toggle="collapse" data-target="#purchase-quick-product-advanced" aria-expanded="false">
                        {{ translate('Advanced product details') }}
                    </button>
                    <div id="purchase-quick-product-advanced" class="collapse">
                        <div class="row">
                            <div class="col-md-6 form-group">
                                <label>{{ translate('Storefront Category') }}</label>
                                <select class="form-control" name="category_id">
                                    <option value="">{{ translate('None') }}</option>
                                    @foreach($quickProductCategories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach
                                </select>
                            </div>
                            <div class="col-md-12 form-group">
                                <label>{{ translate('Description') }}</label>
                                <textarea class="form-control" name="description" rows="3" maxlength="5000"></textarea>
                            </div>
                        </div>
                        <small class="text-muted">{{ translate('Images, SEO, and variations remain available in the full product editor after creation.') }}</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-dismiss="modal">{{ translate('Cancel') }}</button>
                    <button type="submit" class="btn btn-primary">{{ translate('Create and add item') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

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
        const templateElement = document.getElementById('purchase-item-template');
        const barcodeInput = document.getElementById('purchase-barcode-input');
        const barcodeStatus = document.getElementById('purchase-barcode-status');
        const lookupUrl = @json(route('operations.purchase-orders.product-lookup'));
        const quickCreateUrl = @json(route('operations.purchase-orders.quick-products.store'));
        const quickProductAllowed = @json($quickProductAllowed);
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
            rows.insertAdjacentHTML('beforeend', templateElement.innerHTML.replaceAll('__INDEX__', index++));
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
            row.querySelector('.tax-enabled').checked = Boolean(initial.tax_enabled);
            if (initial.tax_rate !== undefined) row.querySelector('.tax-rate').value = initial.tax_rate;

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

        function registerQuickProduct(data) {
            const productOption = new Option(data.name, data.product_id);
            productOption.dataset.cost = data.cost_price ?? '';
            productOption.dataset.regular = data.regular_price ?? '';
            const stockLabel = `${data.variant || @json(translate('Default'))} | ${data.sku || '-'} | ${data.barcode || '-'} | ${@json(translate('Qty'))}: ${data.opening_stock || 0}`;
            const stockOption = new Option(stockLabel, data.product_stock_id);
            stockOption.dataset.productId = data.product_id;
            stockOption.dataset.price = data.regular_price ?? '';

            document.querySelectorAll('.product-select').forEach(select => select.add(productOption.cloneNode(true)));
            document.querySelectorAll('.stock-select').forEach(select => select.add(stockOption.cloneNode(true)));
            templateElement.content.querySelector('.product-select').add(productOption.cloneNode(true));
            templateElement.content.querySelector('.stock-select').add(stockOption.cloneNode(true));
        }

        function showNotFound(payload, identity) {
            document.getElementById('purchase-missing-product-query').textContent = payload.query || identity;
            $('#purchase-product-not-found-modal').modal('show');
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
                if (!response.ok || !payload.ok) {
                    if (response.status === 404 && payload.reason === 'not_found') {
                        showNotFound(payload, identity);
                        return;
                    }
                    throw new Error(payload.message || notFoundMessage);
                }

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
        document.getElementById('purchase-correct-search').addEventListener('click', () => {
            $('#purchase-product-not-found-modal').modal('hide');
            setTimeout(() => {
                barcodeInput.select();
                barcodeInput.focus();
            }, 200);
        });
        if (quickProductAllowed) {
            const quickForm = document.getElementById('purchase-quick-product-form');
            const quickErrors = document.getElementById('purchase-quick-product-errors');
            const quickCost = quickForm.querySelector('.quick-cost-price');
            const quickMargin = quickForm.querySelector('.quick-margin-percent');
            const quickRegular = quickForm.querySelector('.quick-regular-price');
            const quickSale = quickForm.querySelector('.quick-sale-price');

            document.getElementById('purchase-open-quick-product').addEventListener('click', () => {
                const identity = barcodeInput.value.trim();
                quickForm.reset();
                quickForm.querySelector('[name="unit"]').value = 'pc';
                quickForm.querySelector('[name="barcode"]').value = identity;
                quickErrors.classList.add('d-none');
                quickErrors.innerHTML = '';
                $('#purchase-product-not-found-modal').modal('hide');
                $('#purchase-quick-product-modal').modal('show');
                setTimeout(() => quickForm.querySelector('[name="name"]').focus(), 200);
            });

            function quickPricing(changed) {
                const cost = Number(quickCost.value) || 0;
                if (changed === 'margin' && quickMargin.value !== '') {
                    quickRegular.value = (cost * (1 + (Number(quickMargin.value) || 0) / 100)).toFixed(2);
                } else if (cost > 0 && quickRegular.value !== '') {
                    quickMargin.value = (((Number(quickRegular.value) - cost) / cost) * 100).toFixed(2);
                }
                quickSale.setCustomValidity(
                    quickSale.value !== '' && Number(quickSale.value) > Number(quickRegular.value)
                        ? @json(translate('Sale price must not exceed the regular price.'))
                        : ''
                );
            }
            quickCost.addEventListener('input', () => quickPricing('cost'));
            quickMargin.addEventListener('input', () => quickPricing('margin'));
            quickRegular.addEventListener('input', () => quickPricing('regular'));
            quickSale.addEventListener('input', () => quickPricing('sale'));

            document.getElementById('quick-product-family').addEventListener('change', function () {
                const subFamily = document.getElementById('quick-product-sub-family');
                Array.from(subFamily.options).forEach(option => {
                    option.hidden = option.dataset.familyId && option.dataset.familyId !== this.value;
                });
                if (subFamily.selectedOptions[0]?.hidden) subFamily.value = '';
            });

            quickForm.addEventListener('submit', async event => {
                event.preventDefault();
                quickErrors.classList.add('d-none');
                const submit = quickForm.querySelector('[type="submit"]');
                submit.disabled = true;
                try {
                    const response = await fetch(quickCreateUrl, {
                        method: 'POST',
                        headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                        body: new FormData(quickForm)
                    });
                    const payload = await response.json();
                    if (!response.ok || !payload.ok) {
                        const errors = payload.errors
                            ? Object.values(payload.errors).flat()
                            : [payload.message || @json(translate('Product could not be created.'))];
                        quickErrors.innerHTML = errors.map(error => `<div>${String(error).replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]))}</div>`).join('');
                        quickErrors.classList.remove('d-none');
                        return;
                    }
                    registerQuickProduct(payload.data);
                    const row = addRow(payload.data);
                    $('#purchase-quick-product-modal').modal('hide');
                    barcodeInput.value = '';
                    barcodeStatus.className = 'form-text text-success';
                    barcodeStatus.textContent = `${payload.data.name} - ${@json(translate('created and added'))}`;
                    setTimeout(() => row.querySelector('.quantity-input').focus(), 200);
                } catch (error) {
                    quickErrors.textContent = @json(translate('The product could not be created. Check the connection and try again.'));
                    quickErrors.classList.remove('d-none');
                } finally {
                    submit.disabled = false;
                }
            });
        }
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
