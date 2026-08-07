@extends('backend.layouts.app')
@section('content')
<div class="aiz-titlebar mt-2 mb-4"><h1 class="h3">{{ translate('Bulk Catalog Import') }}</h1></div>
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
<div class="card"><div class="card-body">
    <p>{{ translate('Upload an Excel file and an optional ZIP containing locally designed images. The file is analysed before any records are saved.') }}</p>
    <p><a href="{{ route('bulk-catalog.template', 'categories') }}">{{ translate('Download category template') }}</a> | <a href="{{ route('bulk-catalog.template', 'brands') }}">{{ translate('Download brand template') }}</a> | <a href="{{ route('bulk-catalog.template', 'products') }}">{{ translate('Download product template') }}</a></p>
    <ul><li>{{ translate('Categories use row_key and parent_row_key and support unlimited nesting.') }}</li><li>{{ translate('Brands update by normalized name; products update by SKU, then barcode, then name and category.') }}</li><li>{{ translate('Image columns use filenames inside the ZIP: cover_image_file, banner_image_file, icon_file, logo_file, thumbnail_file, gallery_files.') }}</li></ul>
    <form method="POST" action="{{ route('bulk-catalog.preview') }}" enctype="multipart/form-data">@csrf
        <div class="row"><div class="col-md-3 form-group"><label>{{ translate('Import type') }}</label><select name="type" class="form-control" required><option value="categories">{{ translate('Categories') }}</option><option value="brands">{{ translate('Brands') }}</option><option value="products">{{ translate('Products') }}</option></select></div><div class="col-md-4 form-group"><label>{{ translate('Excel file') }}</label><input name="spreadsheet" type="file" accept=".xlsx,.xls,.csv" class="form-control" required></div><div class="col-md-4 form-group"><label>{{ translate('Images ZIP') }}</label><input name="images_zip" type="file" accept=".zip" class="form-control"></div><div class="col-md-1 form-group d-flex align-items-end"><button class="btn btn-primary">{{ translate('Preview') }}</button></div></div>
    </form>
</div></div>
@endsection
