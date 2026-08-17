@extends('backend.layouts.app')
@section('content')
<div class="aiz-titlebar mt-2 mb-4"><h1 class="h3">{{ translate('Bulk Import Preview') }}</h1></div>
@if ($errors->any())
    <div class="alert alert-danger" role="alert">
        <strong>{{ translate('The import could not be completed.') }}</strong>
        <ul class="mb-0 mt-2">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
<div class="card"><div class="card-body">
    <div class="row text-center"><div class="col"><strong>{{ $preview['created'] }}</strong><br>{{ translate('Will be created') }}</div><div class="col"><strong>{{ $preview['updated'] }}</strong><br>{{ translate('Will be updated') }}</div>@if($preview['type'] === 'products')<div class="col"><strong>{{ $preview['product_groups'] }}</strong><br>{{ translate('Products') }}</div><div class="col"><strong>{{ $preview['variant_rows'] }}</strong><br>{{ translate('Variant rows') }}</div>@endif<div class="col"><strong>{{ count($preview['errors']) }}</strong><br>{{ translate('Errors') }}</div></div>
    @if($preview['errors'])<div class="alert alert-danger mt-3"><ul class="mb-0">@foreach($preview['errors'] as $error)<li>{{ $error }}</li>@endforeach</ul></div><a class="btn btn-secondary" href="{{ route('bulk-catalog.index') }}">{{ translate('Back') }}</a>@else
        <div class="alert alert-info mt-3">{{ translate('No records have been saved yet. Confirm to apply this import.') }}</div>
        @if($preview['type'] === 'products' && $preview['replace_product_images'])<div class="alert alert-warning">{{ translate('Supplied product images will replace existing images. Old files are permanently deleted only when no other record uses them.') }}</div>@endif
        <form method="POST" action="{{ route('bulk-catalog.confirm') }}">@csrf<input type="hidden" name="token" value="{{ $preview['token'] }}"><button class="btn btn-success" onclick="return confirm('{{ translate('Apply this bulk import?') }}')">{{ translate('Confirm Import') }}</button><a class="btn btn-light" href="{{ route('bulk-catalog.index') }}">{{ translate('Cancel') }}</a></form>
    @endif
</div></div>
@endsection
