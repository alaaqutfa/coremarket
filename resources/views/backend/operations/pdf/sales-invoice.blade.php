<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $documentTitle }} - {{ $documentNumber }}</title>
    <style>
        @page { margin: {{ $template['margins_mm']['top'] }}mm {{ $template['margins_mm']['right'] }}mm {{ $template['margins_mm']['bottom'] }}mm {{ $template['margins_mm']['left'] }}mm; }
        body { color: #0f172a; font-family: dejavusans, sans-serif; font-size: {{ $template['settings']['font_size'] }}px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { padding: 6px; vertical-align: top; }
        .header { border-bottom: 3px solid {{ $branding['color'] }}; margin-bottom: 16px; padding-bottom: 10px; }
        .title { color: {{ $branding['color'] }}; font-size: 20px; font-weight: bold; text-align: right; }
        .muted { color: {{ $branding['secondary_color'] }}; }
        .info td, .items td, .totals td { border-bottom: 1px solid #e2e8f0; }
        .items th { background: {{ $branding['color'] }}; color: #fff; font-size: 8px; text-align: left; }
        .items td { font-size: 8px; }
        .number { text-align: right; white-space: nowrap; }
        .totals { margin-left: 52%; width: 48%; }
        .grand-total { color: {{ $branding['color'] }}; font-size: 12px; font-weight: bold; }
        .footer { border-top: 1px solid #cbd5e1; color: #64748b; font-size: 8px; margin-top: 18px; padding-top: 8px; }
    </style>
</head>
<body>
    @php($settings = $template['settings'])
    @php($columns = $settings['columns'])
    <table class="header">
        <tr>
            <td width="55%" style="text-align: {{ $settings['logo_position'] }};">
                @if($settings['logo_enabled'] && $branding['logo_path'])
                    <img src="{{ $branding['logo_path'] }}" style="max-height: 42px; max-width: 180px;">
                @elseif($settings['show_store_name'])
                    <div style="font-size: 16px; font-weight: bold;">{{ $branding['store_name'] }}</div>
                @endif
                <div class="muted">{{ $branding['address'] }}</div>
                <div class="muted">{{ $branding['email'] }}{{ $branding['email'] && $branding['phone'] ? ' | ' : '' }}{{ $branding['phone'] }}</div>
            </td>
            <td width="45%" class="title">{{ $documentTitle }}</td>
        </tr>
    </table>

    <table class="info">
        <tr><td><strong>Invoice No.</strong></td><td>{{ $documentNumber }}</td><td><strong>Date</strong></td><td>{{ $documentDate ?: '-' }}</td></tr>
        <tr><td><strong>Payment</strong></td><td>{{ ucwords(str_replace('_', ' ', $order->payment_type)) }}</td><td><strong>Status</strong></td><td>{{ ucwords(str_replace('_', ' ', $order->payment_status)) }}</td></tr>
        @if($settings['show_customer_info'])
            <tr><td><strong>Customer</strong></td><td>{{ $customer['name'] }}</td><td><strong>Phone</strong></td><td>{{ $customer['phone'] ?: '-' }}</td></tr>
            <tr><td><strong>Address</strong></td><td colspan="3">{{ collect([$customer['address'], $customer['city'], $customer['country']])->filter()->implode(', ') ?: '-' }}</td></tr>
        @endif
        @if($order->delivery)
            <tr><td><strong>Delivery</strong></td><td>{{ ucwords(str_replace('_', ' ', $order->delivery->status)) }}</td><td><strong>COD</strong></td><td>{{ $order->delivery->cod_amount !== null ? coremarket_money($order->delivery->cod_amount, $currency) : 'Not required' }}</td></tr>
        @endif
    </table>

    <table class="items" style="margin-top: 16px;">
        <thead><tr>
            @if(in_array('product', $columns, true))<th>Product</th>@endif
            @if(in_array('sku', $columns, true) || in_array('barcode', $columns, true))<th>SKU / Barcode</th>@endif
            @if(in_array('quantity', $columns, true))<th>Qty</th>@endif
            @if(in_array('unit_price', $columns, true))<th>Unit Price</th>@endif
            @if($settings['show_tax'] && in_array('tax', $columns, true))<th>Tax</th>@endif
            @if($settings['show_discount'] && in_array('discount', $columns, true))<th>Discount</th>@endif
            @if(in_array('line_total', $columns, true))<th>Line Total</th>@endif
        </tr></thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    @if(in_array('product', $columns, true))<td>{{ $row['product_name'] }}@if($row['variant'])<br><span class="muted">{{ $row['variant'] }}</span>@endif</td>@endif
                    @if(in_array('sku', $columns, true) || in_array('barcode', $columns, true))<td>@if($settings['show_sku']){{ $row['sku'] ?: '-' }}@endif @if($settings['show_barcode'])<br><span class="muted">{{ $row['barcode'] ?: '-' }}</span>@endif</td>@endif
                    @if(in_array('quantity', $columns, true))<td class="number">{{ coremarket_quantity($row['quantity']) }}</td>@endif
                    @if(in_array('unit_price', $columns, true))<td class="number">{{ coremarket_money($row['unit_price'], $currency) }}</td>@endif
                    @if($settings['show_tax'] && in_array('tax', $columns, true))<td class="number">{{ coremarket_money($row['tax_amount'], $currency) }}</td>@endif
                    @if($settings['show_discount'] && in_array('discount', $columns, true))<td class="number">{{ coremarket_money($row['discount'], $currency) }}</td>@endif
                    @if(in_array('line_total', $columns, true))<td class="number">{{ coremarket_money($row['line_total'], $currency) }}</td>@endif
                </tr>
            @empty
                <tr><td colspan="7" style="text-align: center;">No order items available.</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="totals" style="margin-top: 14px;">
        <tr><td>Subtotal</td><td class="number">{{ coremarket_money($totals['subtotal'], $currency) }}</td></tr>
        <tr><td>Tax</td><td class="number">{{ coremarket_money($totals['tax'], $currency) }}</td></tr>
        <tr><td>Shipping</td><td class="number">{{ coremarket_money($totals['shipping'], $currency) }}</td></tr>
        <tr><td>Discount</td><td class="number">{{ coremarket_money($totals['discount'], $currency) }}</td></tr>
        <tr class="grand-total"><td>Total</td><td class="number">{{ coremarket_money($totals['total'], $currency) }}</td></tr>
        <tr><td>Paid</td><td class="number">{{ coremarket_money($totals['paid'], $currency) }}</td></tr>
        <tr><td>Outstanding</td><td class="number">{{ coremarket_money($totals['outstanding'], $currency) }}</td></tr>
    </table>

    @if($settings['show_footer'])<div class="footer">
        {{ $settings['footer_text'] }}
        This invoice uses stored order item prices and totals. Customer-specific price lists are not exposed.
    </div>@endif
</body>
</html>
