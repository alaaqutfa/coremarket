@extends('backend.layouts.app')
@section('content')
<div class="aiz-titlebar mt-2 mb-4"><h1 class="h3">{{ translate('Bulk Import Preview') }}</h1></div>
<div class="card"><div class="card-body">
    <div class="row text-center"><div class="col"><strong>{{ $preview['created'] }}</strong><br>{{ translate('Will be created') }}</div><div class="col"><strong>{{ $preview['updated'] }}</strong><br>{{ translate('Will be updated') }}</div><div class="col"><strong>{{ count($preview['errors']) }}</strong><br>{{ translate('Errors') }}</div></div>
    @if($preview['errors'])<div class="alert alert-danger mt-3"><ul class="mb-0">@foreach($preview['errors'] as $error)<li>{{ $error }}</li>@endforeach</ul></div><a class="btn btn-secondary" href="{{ route('bulk-catalog.index') }}">{{ translate('Back') }}</a>@else
        <div class="alert alert-info mt-3">{{ translate('No records have been saved yet. Confirm to apply this import.') }}</div>
        <form method="POST" action="{{ route('bulk-catalog.confirm') }}">@csrf<input type="hidden" name="token" value="{{ $preview['token'] }}"><button class="btn btn-success" onclick="return confirm('{{ translate('Apply this bulk import?') }}')">{{ translate('Confirm Import') }}</button><a class="btn btn-light" href="{{ route('bulk-catalog.index') }}">{{ translate('Cancel') }}</a></form>
    @endif
</div></div>
@endsection
