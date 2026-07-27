@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3"><h1 class="h3">{{ translate('Print Labels') }}</h1></div>
<form method="POST" target="_blank" action="{{ route('operations.labels.pdf') }}">@csrf
    <div class="card"><div class="card-body">
        <p class="text-muted">PDF preview only. Barcode values are printed as text until a safe 1D barcode renderer is adopted.</p>
        <div class="row">
            <div class="col-md-4 form-group"><label>Label Type</label><select class="form-control" name="template_type" id="label-type"><option value="price_label">Price Label</option><option value="barcode_label">Barcode Label</option></select></div>
            <div class="col-md-8 form-group"><label>Template</label><select class="form-control" name="template_id">@foreach($priceTemplates->concat($barcodeTemplates) as $template)<option value="{{ $template->id }}" data-type="{{ $template->template_type }}">{{ $template->name }}</option>@endforeach</select></div>
        </div>
        <div class="table-responsive" style="max-height: 500px"><table class="table aiz-table">
            <thead><tr><th></th><th>Product</th><th>SKU</th><th>Barcode</th><th>Regular Price</th></tr></thead>
            <tbody>@foreach($products as $product) @php($stock = $product->stocks->first())<tr>
                <td><input type="checkbox" name="product_ids[]" value="{{ $product->id }}"></td>
                <td>{{ $product->name }}</td><td>{{ $stock?->sku ?: '-' }}</td><td>{{ $stock?->barcode ?: $product->barcode ?: '-' }}</td><td>{{ coremarket_price($product->unit_price) }}</td>
            </tr>@endforeach</tbody>
        </table></div>
    </div><div class="card-footer text-right"><button class="btn btn-primary">Generate PDF Preview</button></div></div>
</form>
@endsection
