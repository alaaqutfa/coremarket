@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3"><h5 class="mb-0 h6">{{ translate('Create Cashbox') }}</h5></div>
<div class="card"><div class="card-body"><form method="POST" action="{{ route('operations.cashboxes.store') }}">@csrf
    <div class="row gutters-10"><div class="col-md-6 mb-3"><label>{{ translate('Name') }}</label><input required class="form-control" name="name" value="{{ old('name') }}"></div><div class="col-md-6 mb-3"><label>{{ translate('Code') }}</label><input class="form-control" name="code" value="{{ old('code') }}"></div><div class="col-md-6 mb-3"><label>{{ translate('Location') }}</label><input class="form-control" name="location" value="{{ old('location') }}"></div><div class="col-md-3 mb-3"><label>{{ translate('Currency') }}</label><input class="form-control" name="currency" value="{{ old('currency') }}"></div><div class="col-md-3 mb-3"><label>{{ translate('Status') }}</label><select class="form-control" name="status"><option value="active" @selected(old('status', 'active') === 'active')>{{ translate('Active') }}</option><option value="inactive" @selected(old('status') === 'inactive')>{{ translate('Inactive') }}</option></select></div><div class="col-md-6 mb-3"><label>{{ translate('Assigned User') }}</label><select class="form-control" name="assigned_user_id"><option value="">{{ translate('Unassigned') }}</option>@foreach($users as $user)<option value="{{ $user->id }}" @selected((string) old('assigned_user_id') === (string) $user->id)>{{ $user->name }}</option>@endforeach</select></div></div>
    <button class="btn btn-primary">{{ translate('Create Cashbox') }}</button><a href="{{ route('operations.cashboxes') }}" class="btn btn-light">{{ translate('Cancel') }}</a>
</form></div></div>
@endsection
