@extends('backend.layouts.app')

@section('content')
@php($editing = $productFamily->exists)
<div class="aiz-titlebar text-left mt-2 mb-3"><h5 class="mb-0 h6">{{ $editing ? translate('Edit Product Family') : translate('Create Product Family') }}</h5></div>
<form method="POST" action="{{ $editing ? route('operations.inventory.families.update', $productFamily) : route('operations.inventory.families.store') }}">
    @csrf @if($editing) @method('PUT') @endif
    <div class="card"><div class="card-body">
        <div class="row">
            <div class="form-group col-md-6"><label>{{ translate('Name') }}</label><input class="form-control" name="name" required value="{{ old('name', $productFamily->name) }}"></div>
            <div class="form-group col-md-3"><label>{{ translate('Code') }}</label><input class="form-control text-uppercase" name="code" value="{{ old('code', $productFamily->code) }}"></div>
            <div class="form-group col-md-3"><label>{{ translate('Level') }}</label><select class="form-control" name="level">@foreach(['family','sub_family'] as $level)<option value="{{ $level }}" @selected(old('level', $productFamily->level ?: 'family') === $level)>{{ translate(ucwords(str_replace('_', ' ', $level))) }}</option>@endforeach</select></div>
            <div class="form-group col-md-6"><label>{{ translate('Parent Family') }}</label><select class="form-control aiz-selectpicker" data-live-search="true" name="parent_id"><option value="">{{ translate('None') }}</option>@foreach($families as $family)<option value="{{ $family->id }}" @selected((string) old('parent_id', $productFamily->parent_id) === (string) $family->id)>{{ $family->name }}</option>@endforeach</select><small class="text-muted">{{ translate('Required only for a sub family.') }}</small></div>
            <div class="form-group col-md-6"><label>{{ translate('Description') }}</label><textarea class="form-control" name="description" rows="3">{{ old('description', $productFamily->description) }}</textarea></div>
            <div class="form-group col-md-3"><label><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $productFamily->exists ? $productFamily->is_active : true))> {{ translate('Active') }}</label></div>
        </div>
        <button class="btn btn-primary">{{ translate('Save') }}</button>
        <a class="btn btn-light" href="{{ route('operations.inventory.families.index') }}">{{ translate('Cancel') }}</a>
    </div></div>
</form>
@endsection
