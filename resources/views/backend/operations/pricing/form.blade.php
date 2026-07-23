@extends('backend.layouts.app')

@section('content')
@php($editing = $priceList->exists)
<div class="aiz-titlebar text-left mt-2 mb-3"><h5 class="mb-0 h6">{{ $editing ? translate('Edit Price List') : translate('Create Price List') }}</h5></div>
<form method="POST" action="{{ $editing ? route('operations.price-lists.update', $priceList) : route('operations.price-lists.store') }}">
    @csrf @if($editing) @method('PUT') @endif
    <div class="card"><div class="card-body">
        <div class="row">
            <div class="form-group col-md-6"><label>{{ translate('Name') }}</label><input class="form-control" name="name" required value="{{ old('name', $priceList->name) }}"></div>
            <div class="form-group col-md-3"><label>{{ translate('Code') }}</label><input class="form-control text-uppercase" name="code" required value="{{ old('code', $priceList->code) }}"></div>
            <div class="form-group col-md-3"><label>{{ translate('Currency') }}</label><input class="form-control text-uppercase" name="currency" required value="{{ old('currency', $priceList->currency ?: 'USD') }}"></div>
            <div class="form-group col-md-6"><label>{{ translate('Type') }}</label><select class="form-control" name="type">@foreach(['retail','wholesale','vip','custom'] as $type)<option value="{{ $type }}" @selected(old('type', $priceList->type ?: 'custom') === $type)>{{ translate(ucfirst($type)) }}</option>@endforeach</select></div>
            <div class="form-group col-md-6"><label>{{ translate('Pricing Method') }}</label><select class="form-control" name="pricing_method">@foreach(['fixed_price','margin_over_cost','discount_from_regular'] as $method)<option value="{{ $method }}" @selected(old('pricing_method', $priceList->pricing_method ?: 'fixed_price') === $method)>{{ translate(ucwords(str_replace('_', ' ', $method))) }}</option>@endforeach</select></div>
            <div class="form-group col-md-6"><label>{{ translate('Default Margin Percent') }}</label><input type="number" min="0" step="0.01" class="form-control" name="margin_percent" value="{{ old('margin_percent', $priceList->margin_percent) }}"></div>
            <div class="form-group col-md-6"><label>{{ translate('Default Discount Percent') }}</label><input type="number" min="0" max="100" step="0.01" class="form-control" name="discount_percent" value="{{ old('discount_percent', $priceList->discount_percent) }}"></div>
            <div class="form-group col-md-3"><label><input type="checkbox" name="is_default" value="1" @checked(old('is_default', $priceList->is_default))> {{ translate('Default list') }}</label></div>
            <div class="form-group col-md-3"><label><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $priceList->exists ? $priceList->is_active : true))> {{ translate('Active') }}</label></div>
        </div>
        <button class="btn btn-primary">{{ translate('Save') }}</button>
        <a class="btn btn-light" href="{{ route('operations.price-lists.index') }}">{{ translate('Cancel') }}</a>
    </div></div>
</form>
@endsection
