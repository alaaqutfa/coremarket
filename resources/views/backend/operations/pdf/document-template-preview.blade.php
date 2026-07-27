<!doctype html><html><head><meta charset="utf-8"><style>
@page { margin: {{ $template['margins_mm']['top'] }}mm {{ $template['margins_mm']['right'] }}mm {{ $template['margins_mm']['bottom'] }}mm {{ $template['margins_mm']['left'] }}mm; }
body { font-family: dejavusans, sans-serif; color: #0f172a; font-size: {{ $template['settings']['font_size'] }}px; }
h1 { color: {{ $template['settings']['primary_color'] }}; border-bottom: 3px solid {{ $template['settings']['primary_color'] }}; }
table { width: 100%; border-collapse: collapse; } th { color: white; background: {{ $template['settings']['primary_color'] }}; } th,td { padding: 6px; border-bottom: 1px solid #e2e8f0; }
</style></head><body>
@if($template['settings']['show_store_name'])<h1>{{ $preview['store_name'] }}</h1>@endif
<h2>{{ \Illuminate\Support\Str::headline($template['template_type']) }} Preview</h2>
<p>Document: {{ $preview['document_number'] }} | {{ $preview['party_name'] }}</p>
<table><tr><th>Product</th><th>SKU / Barcode</th><th>Quantity</th><th>Price</th></tr>
@foreach($preview['items'] as $item)<tr><td>{{ $item['name'] }}</td><td>{{ $item['sku'] }} / {{ $item['barcode'] }}</td><td>{{ coremarket_quantity($item['quantity']) }}</td><td>{{ coremarket_price($item['price']) }}</td></tr>@endforeach</table>
<h3 style="text-align:right;color:{{ $template['settings']['primary_color'] }}">Total: {{ coremarket_price($preview['total']) }}</h3>
@if($template['settings']['show_footer'])<p>{{ $template['settings']['footer_text'] }}</p>@endif
</body></html>
