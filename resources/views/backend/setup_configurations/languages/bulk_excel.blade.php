@extends('backend.layouts.app')
@section('content')
<div class="aiz-titlebar mt-2 mb-4"><h1 class="h3">{{ translate('Bulk Translation Import') }}</h1></div>
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
<div class="card"><div class="card-body"><p>{{ translate('Download the workbook, edit only non-empty translations, then upload it. The workbook contains UI and catalog translation sheets for all active languages.') }}</p><a class="btn btn-info" href="{{ route('bulk-translations.export') }}">{{ translate('Download Translation Workbook') }}</a></div></div>
<div class="card"><div class="card-body"><form method="POST" action="{{ route('bulk-translations.import') }}" enctype="multipart/form-data">@csrf<div class="form-group"><label>{{ translate('Translation workbook') }}</label><input type="file" name="translation_file" accept=".xlsx,.xls" required class="form-control"></div><button class="btn btn-primary">{{ translate('Import Translations') }}</button></form></div></div>
@endsection
