<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $documentTitle }} - {{ $documentNumber }}</title>
    <style>
        @page { margin: {{ $template['margins_mm']['top'] }}mm {{ $template['margins_mm']['right'] }}mm {{ $template['margins_mm']['bottom'] }}mm {{ $template['margins_mm']['left'] }}mm; }
        body { color: #0f172a; font-family: dejavusans, sans-serif; font-size: {{ $template['settings']['font_size'] }}px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { padding: 7px; vertical-align: top; }
        .header { border-bottom: 3px solid {{ $branding['color'] }}; margin-bottom: 16px; padding-bottom: 10px; }
        .title { color: {{ $branding['color'] }}; font-size: 20px; font-weight: bold; text-align: right; }
        .muted { color: {{ $branding['secondary_color'] }}; }
        .items th { background: {{ $branding['color'] }}; color: #fff; text-align: left; }
        .items td { border-bottom: 1px solid #e2e8f0; }
        .number { text-align: right; white-space: nowrap; }
        .footer { border-top: 1px solid #cbd5e1; color: #64748b; font-size: 8px; margin-top: 18px; padding-top: 8px; }
    </style>
</head>
<body>
    @php($settings = $template['settings'])
    @php($columns = $settings['columns'])
    <table class="header"><tr>
        <td width="55%" style="text-align: {{ $settings['logo_position'] }};">
            @if($settings['logo_enabled'] && $branding['logo_path'])
                <img src="{{ $branding['logo_path'] }}" style="max-height: 42px; max-width: 180px;">
            @elseif($settings['show_store_name'])
                <div style="font-size: 16px; font-weight: bold;">{{ $branding['store_name'] }}</div>
            @endif
        </td>
        <td width="45%" class="title">{{ $documentTitle }}</td>
    </tr></table>

    <table>
        <tr><td><strong>Order</strong></td><td>{{ $documentNumber }}</td><td><strong>Date</strong></td><td>{{ $documentDate ?: '-' }}</td></tr>
        <tr><td><strong>Customer</strong></td><td>{{ $customer['name'] }}</td><td><strong>Phone</strong></td><td>{{ $customer['phone'] ?: '-' }}</td></tr>
        <tr><td><strong>Delivery address</strong></td><td colspan="3">{{ collect([$customer['address'], $customer['city'], $customer['country']])->filter()->implode(', ') ?: '-' }}</td></tr>
        <tr><td><strong>Delivery status</strong></td><td>{{ $delivery ? ucwords(str_replace('_', ' ', $delivery->status)) : 'Not assigned' }}</td><td><strong>Delivery employee</strong></td><td>{{ $delivery?->deliveryUser?->name ?: '-' }}</td></tr>
        @if($delivery?->cod_amount !== null)<tr><td><strong>COD amount</strong></td><td>{{ coremarket_money($delivery->cod_amount) }}</td><td><strong>COD status</strong></td><td>{{ ucwords(str_replace('_', ' ', $delivery->cod_collection_status)) }}</td></tr>@endif
    </table>

    <table class="items" style="margin-top: 16px;">
        <thead><tr>
            @if(in_array('product', $columns, true))<th>Product</th>@endif
            @if(in_array('sku', $columns, true) || in_array('barcode', $columns, true))<th>SKU / Barcode</th>@endif
            @if(in_array('quantity', $columns, true))<th>Quantity</th>@endif
        </tr></thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    @if(in_array('product', $columns, true))<td>{{ $row['product_name'] }}@if($row['variant'])<br><span class="muted">{{ $row['variant'] }}</span>@endif</td>@endif
                    @if(in_array('sku', $columns, true) || in_array('barcode', $columns, true))<td>@if($settings['show_sku']){{ $row['sku'] ?: '-' }}@endif @if($settings['show_barcode'])<br><span class="muted">{{ $row['barcode'] ?: '-' }}</span>@endif</td>@endif
                    @if(in_array('quantity', $columns, true))<td class="number">{{ coremarket_quantity($row['quantity']) }}</td>@endif
                </tr>
            @empty
                <tr><td colspan="3" style="text-align: center;">No order items available.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if($settings['show_footer'])<div class="footer">
        {{ $settings['footer_text'] }}
        This operational delivery document intentionally excludes product cost, profit, supplier, and accounting information.
    </div>@endif
</body>
</html>
