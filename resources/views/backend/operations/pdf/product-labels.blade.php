<!doctype html><html><head><meta charset="utf-8"><style>
@page { margin: {{ $template['margins_mm']['top'] }}mm {{ $template['margins_mm']['right'] }}mm {{ $template['margins_mm']['bottom'] }}mm {{ $template['margins_mm']['left'] }}mm; }
body { font-family: dejavusans, sans-serif; color: #0f172a; font-size: {{ $template['settings']['font_size'] }}px; }
.label { text-align:center; border:1px solid #cbd5e1; padding:3mm; min-height:18mm; }
.name { font-weight:bold; color:{{ $template['settings']['primary_color'] }}; }
.barcode { font-family: monospace; letter-spacing: 1px; }
.price { font-size:15px; font-weight:bold; }
</style></head><body>
@foreach($products as $product)<div class="label">
    <div class="name">{{ $product['name'] }}</div>
    @if($template['settings']['show_family'] && $product['family'])<div>{{ $product['family'] }}</div>@endif
    @if($template['settings']['show_sku'] && $product['sku'])<div>SKU: {{ $product['sku'] }}</div>@endif
    @if($template['settings']['show_barcode'] && $product['barcode'])<div class="barcode">*{{ $product['barcode'] }}*</div>@endif
    @if($template['template_type'] === 'price_label')<div class="price">{{ coremarket_price($product['sale_price'] ?? $product['regular_price']) }}</div>@endif
</div>@endforeach
</body></html>
