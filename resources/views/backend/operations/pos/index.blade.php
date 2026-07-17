@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3">
    <div class="row align-items-center">
        <div class="col"><h5 class="mb-0 h6">{{ translate('Web POS') }}</h5></div>
        <div class="col-auto text-muted small">{{ translate('Cash only') }}</div>
    </div>
</div>

@if ($errors->has('pos'))
    <div class="alert alert-danger">{{ $errors->first('pos') }}</div>
@endif

@if (! $openShift)
    <div class="alert alert-warning">
        {{ translate('Open a cashier shift before completing a POS sale.') }}
        @if ($canOpenShift)<a class="alert-link ml-2" href="{{ route('operations.cashboxes') }}">{{ translate('Open a shift') }}</a>@endif
    </div>
@else
    <div class="alert alert-light border d-flex justify-content-between align-items-center">
        <span>{{ translate('Open shift') }} #{{ $openShift->id }} - {{ $openShift->cashbox?->name }}</span>
        <strong>{{ number_format((float) $openShift->expected_cash, 2) }} {{ $openShift->cashbox?->currency }}</strong>
    </div>
@endif

<div class="row gutters-10" id="web-pos-app" data-search-url="{{ route('operations.pos.search') }}">
    <div class="col-lg-7 mb-3">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">{{ translate('Find products') }}</h6></div>
            <div class="card-body">
                <div class="input-group mb-3">
                    <input type="search" id="pos-search" class="form-control" placeholder="{{ translate('Scan barcode, enter SKU, or search by name') }}" autocomplete="off">
                    <div class="input-group-append"><button class="btn btn-primary" type="button" id="pos-search-button">{{ translate('Search') }}</button></div>
                </div>
                <div id="pos-search-status" class="small text-muted mb-2"></div>
                <div class="list-group" id="pos-results"><div class="text-muted text-center py-4">{{ translate('Search for a product to add it to the cart.') }}</div></div>
            </div>
        </div>
    </div>
    <div class="col-lg-5 mb-3">
        <form method="POST" action="{{ route('operations.pos.checkout') }}" class="card h-100" id="pos-checkout-form">
            @csrf
            <div class="card-header d-flex justify-content-between"><h6 class="mb-0">{{ translate('Current sale') }}</h6><button type="button" class="btn btn-sm btn-soft-danger" id="pos-clear-cart">{{ translate('Clear') }}</button></div>
            <div class="card-body d-flex flex-column">
                <div id="pos-item-inputs"></div>
                <input type="hidden" name="pos_request_key" id="pos-request-key">
                <div class="table-responsive flex-grow-1"><table class="table table-sm"><thead><tr><th>{{ translate('Item') }}</th><th class="text-center">{{ translate('Qty') }}</th><th class="text-right">{{ translate('Total') }}</th></tr></thead><tbody id="pos-cart"><tr><td colspan="3" class="text-center text-muted py-4">{{ translate('Cart is empty.') }}</td></tr></tbody></table></div>
                <div class="border-top pt-3 mt-auto">
                    <div class="d-flex justify-content-between mb-1"><span>{{ translate('Subtotal') }}</span><span id="pos-subtotal">0.00</span></div>
                    <div class="d-flex justify-content-between mb-1"><span>{{ translate('Tax') }}</span><span id="pos-tax">0.00</span></div>
                    <div class="d-flex justify-content-between font-weight-bold h6"><span>{{ translate('Grand total') }}</span><span id="pos-grand-total">0.00</span></div>
                    <div class="form-group mt-3 mb-2"><label>{{ translate('Cash received') }}</label><input type="number" name="paid_amount" id="pos-paid-amount" min="0" step="0.000001" class="form-control" required></div>
                    <div class="d-flex justify-content-between text-muted mb-3"><span>{{ translate('Change due') }}</span><span id="pos-change">0.00</span></div>
                    <button type="submit" class="btn btn-primary btn-block" {{ (! $openShift || ! $canSell) ? 'disabled' : '' }}>{{ translate('Complete cash sale') }}</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection

@section('script')
<script>
(() => {
    const app = document.getElementById('web-pos-app');
    if (!app) return;
    const cart = new Map();
    const money = value => Number(value || 0).toFixed(2);
    const results = document.getElementById('pos-results');
    const cartBody = document.getElementById('pos-cart');
    const query = document.getElementById('pos-search');
    const paid = document.getElementById('pos-paid-amount');
    const requestKey = document.getElementById('pos-request-key');

    requestKey.value = window.crypto?.randomUUID ? window.crypto.randomUUID() : `pos-${Date.now()}-${Math.random().toString(16).slice(2)}`;

    function totals() {
        return [...cart.values()].reduce((total, item) => ({
            subtotal: total.subtotal + item.price * item.quantity,
            tax: total.tax + item.tax * item.quantity,
        }), {subtotal: 0, tax: 0});
    }

    function renderCart() {
        const total = totals();
        const grandTotal = total.subtotal + total.tax;
        const itemInputs = document.getElementById('pos-item-inputs');
        itemInputs.innerHTML = '';
        [...cart.values()].forEach((item, index) => {
            [['product_id', item.product_id], ['product_stock_id', item.product_stock_id], ['quantity', item.quantity]].forEach(([name, value]) => {
                const input = document.createElement('input');
                input.type = 'hidden'; input.name = `items[${index}][${name}]`; input.value = value;
                itemInputs.appendChild(input);
            });
        });
        document.getElementById('pos-subtotal').textContent = money(total.subtotal);
        document.getElementById('pos-tax').textContent = money(total.tax);
        document.getElementById('pos-grand-total').textContent = money(grandTotal);
        document.getElementById('pos-change').textContent = money(Math.max(0, Number(paid.value || 0) - grandTotal));
        cartBody.innerHTML = '';
        if (!cart.size) {
            cartBody.innerHTML = `<tr><td colspan="3" class="text-center text-muted py-4">{{ translate('Cart is empty.') }}</td></tr>`;
            return;
        }
        cart.forEach(item => {
            const row = document.createElement('tr');
            row.innerHTML = `<td><strong></strong><div class="small text-muted"></div></td><td class="text-center"><div class="btn-group btn-group-sm"><button type="button" class="btn btn-light" data-action="decrease">-</button><span class="btn btn-light disabled"></span><button type="button" class="btn btn-light" data-action="increase">+</button></div></td><td class="text-right"><span></span><button type="button" class="btn btn-link btn-sm text-danger p-0 d-block ml-auto" data-action="remove">{{ translate('Remove') }}</button></td>`;
            row.querySelector('strong').textContent = item.name;
            row.querySelector('.small').textContent = [item.variation, item.sku || item.barcode, `${item.available_stock} {{ translate('in stock') }}`].filter(Boolean).join(' - ');
            row.querySelector('.disabled').textContent = item.quantity;
            row.querySelector('.text-right span').textContent = money(item.price * item.quantity + item.tax * item.quantity);
            row.querySelectorAll('[data-action]').forEach(button => button.addEventListener('click', () => updateCart(item.product_stock_id, button.dataset.action)));
            cartBody.appendChild(row);
        });
    }

    function updateCart(key, action) {
        const item = cart.get(key);
        if (!item) return;
        if (action === 'increase' && item.quantity < item.available_stock) item.quantity++;
        if (action === 'decrease') item.quantity--;
        if (action === 'remove' || item.quantity < 1) cart.delete(key);
        renderCart();
    }

    function addResult(item) {
        if (!item.product_stock_id) return;
        const current = cart.get(item.product_stock_id);
        if (current) {
            updateCart(item.product_stock_id, 'increase');
            return;
        }
        cart.set(item.product_stock_id, {...item, quantity: 1, tax: (item.taxes || []).reduce((sum, tax) => sum + (tax.type === 'percent' ? item.price * tax.value / 100 : tax.value), 0)});
        renderCart();
    }

    async function search() {
        const q = query.value.trim();
        if (!q) return;
        results.innerHTML = `<div class="text-center text-muted py-3">{{ translate('Searching...') }}</div>`;
        try {
            const response = await fetch(`${app.dataset.searchUrl}?q=${encodeURIComponent(q)}`, {headers: {'Accept': 'application/json'}});
            if (!response.ok) throw new Error('Search unavailable');
            const items = await response.json();
            results.innerHTML = '';
            if (!items.length) results.innerHTML = `<div class="text-center text-muted py-3">{{ translate('No products found.') }}</div>`;
            items.forEach(item => {
                const button = document.createElement('button');
                button.type = 'button'; button.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';
                button.disabled = !item.product_stock_id || Number(item.available_stock) < 1;
                button.innerHTML = `<span><strong></strong><small class="d-block text-muted"></small></span><span class="text-right"><strong></strong><small class="d-block text-muted"></small></span>`;
                button.querySelector('strong').textContent = item.name;
                button.querySelector('small').textContent = [item.variation, item.sku || item.barcode, item.matched_by].filter(Boolean).join(' - ');
                button.querySelector('.text-right strong').textContent = money(item.price);
                button.querySelector('.text-right small').textContent = `${item.available_stock} {{ translate('in stock') }}`;
                button.addEventListener('click', () => addResult(item));
                results.appendChild(button);
            });
        } catch (error) {
            results.innerHTML = `<div class="alert alert-danger mb-0">{{ translate('Product search is unavailable.') }}</div>`;
        }
    }

    document.getElementById('pos-search-button').addEventListener('click', search);
    query.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); search(); } });
    paid.addEventListener('input', renderCart);
    document.getElementById('pos-clear-cart').addEventListener('click', () => { cart.clear(); renderCart(); });
    renderCart();
})();
</script>
@endsection
