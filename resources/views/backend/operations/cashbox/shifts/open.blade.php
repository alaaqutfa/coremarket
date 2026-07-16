@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3"><h5 class="mb-0 h6">{{ translate('Open Cashier Shift') }}: {{ $cashbox->name }}</h5></div>
@if($cashbox->isInactive())<div class="alert alert-warning">{{ translate('This cashbox is inactive and cannot open a shift.') }}</div>@else<div class="card"><div class="card-body"><form method="POST" action="{{ route('operations.cash-shifts.open', $cashbox) }}">@csrf<div class="form-group"><label>{{ translate('Opening Balance') }}</label><input required min="0" step="0.000001" type="number" class="form-control" name="opening_balance" value="{{ old('opening_balance', 0) }}"></div><div class="form-group"><label>{{ translate('Notes') }}</label><textarea class="form-control" name="notes">{{ old('notes') }}</textarea></div><button class="btn btn-primary">{{ translate('Open Shift') }}</button><a class="btn btn-light" href="{{ route('operations.cashboxes.show', $cashbox) }}">{{ translate('Cancel') }}</a></form></div></div>@endif
@endsection
