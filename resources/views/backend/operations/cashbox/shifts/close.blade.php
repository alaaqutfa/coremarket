@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3"><h5 class="mb-0 h6">{{ translate('Close Cashier Shift') }} #{{ $shift->id }}</h5></div>
<div class="card"><div class="card-body"><div class="alert alert-info">{{ translate('Expected Cash') }}: <strong>{{ number_format((float) $expectedCash, 2) }} {{ $shift->cashbox?->currency }}</strong></div><form method="POST" action="{{ route('operations.cash-shifts.close', $shift) }}">@csrf<div class="form-group"><label>{{ translate('Actual Cash') }}</label><input required min="0" step="0.000001" type="number" class="form-control" name="actual_cash" value="{{ old('actual_cash') }}"></div><div class="form-group"><label>{{ translate('Close Notes') }}</label><textarea class="form-control" name="close_notes">{{ old('close_notes') }}</textarea></div><button class="btn btn-primary">{{ translate('Close Shift') }}</button><a class="btn btn-light" href="{{ route('operations.cash-shifts.show', $shift) }}">{{ translate('Cancel') }}</a></form></div></div>
@endsection
