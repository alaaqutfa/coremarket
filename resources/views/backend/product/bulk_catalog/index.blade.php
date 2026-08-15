@extends('backend.layouts.app')
@section('content')
<div class="aiz-titlebar mt-2 mb-4"><h1 class="h3">{{ translate('Bulk Catalog') }}</h1></div>
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if ($errors->any())
    <div class="alert alert-danger" role="alert">
        <strong>{{ translate('The import preview could not be completed.') }}</strong>
        <ul class="mb-0 mt-2">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
<div class="row">
<div class="col-lg-8"><div class="card"><div class="card-header"><h5 class="mb-0">{{ translate('Import / Update') }}</h5></div><div class="card-body">
    <p>{{ translate('Upload an Excel file and an optional ZIP containing locally designed images. The file is validated and previewed before any records are saved.') }}</p>
    <form method="POST" action="{{ route('bulk-catalog.preview') }}" enctype="multipart/form-data">@csrf
        <div class="row"><div class="col-md-3 form-group"><label>{{ translate('Import type') }}</label><select name="type" class="form-control" required><option value="categories" @selected(old('type', 'categories') === 'categories')>{{ translate('Categories') }}</option><option value="brands" @selected(old('type') === 'brands')>{{ translate('Brands') }}</option><option value="products" @selected(old('type') === 'products')>{{ translate('Products') }}</option></select></div><div class="col-md-4 form-group"><label>{{ translate('Excel file') }}</label><input name="spreadsheet" type="file" accept=".xlsx,.xls,.csv" class="form-control" required></div><div class="col-md-4 form-group"><label>{{ translate('Images ZIP') }}</label><input name="images_zip" type="file" accept=".zip" class="form-control"></div><div class="col-md-1 form-group d-flex align-items-end"><button class="btn btn-primary">{{ translate('Preview') }}</button></div></div>
    </form>
</div></div></div>
<div class="col-lg-4"><div class="card"><div class="card-header"><h5 class="mb-0">{{ translate('Template & Export') }}</h5></div><div class="card-body">
    <p><a href="{{ route('bulk-catalog.template', 'categories') }}">{{ translate('Download category template') }}</a></p>
    <p><a href="{{ route('bulk-catalog.template', 'brands') }}">{{ translate('Download brand template') }}</a></p>
    <p><a href="{{ route('bulk-catalog.template', 'products') }}">{{ translate('Download product template') }}</a></p>
    <a href="{{ route('bulk-catalog.products.export') }}" class="btn btn-outline-primary btn-block">{{ translate('Export products') }}</a>
</div></div></div>
</div>
<div class="card"><div class="card-header"><h5 class="mb-0">{{ translate('Instructions') }}</h5></div><div class="card-body"><ul class="mb-0"><li>{{ translate('Categories use row_key and parent_row_key and support unlimited nesting.') }}</li><li>{{ translate('Brands update by normalized name; products update by SKU, then barcode, then name and category.') }}</li><li>{{ translate('Image columns use filenames inside the ZIP: cover_image_file, banner_image_file, icon_file, logo_file, thumbnail_file, gallery_files.') }}</li><li>{{ translate('Products may use the optional information_sections JSON column. A missing or blank value preserves existing sections; [] deletes all sections; a JSON array replaces them.') }}</li></ul></div></div>
@endsection
