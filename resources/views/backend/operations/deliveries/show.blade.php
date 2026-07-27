@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3 d-flex align-items-center justify-content-between">
    <div>
        <h1 class="h3 mb-1">{{ translate('Delivery') }} #{{ $snapshot['order_code'] }}</h1>
        <a href="{{ route('operations.deliveries.index') }}">{{ translate('Back to Delivery Board') }}</a>
    </div>
    <span class="badge badge-soft-primary fs-14">{{ translate(str_replace('_', ' ', $delivery->status)) }}</span>
</div>

<div class="row">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header"><h5 class="mb-0 h6">{{ translate('Delivery Details') }}</h5></div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6 mb-3"><small class="text-muted">{{ translate('Customer') }}</small><div class="fw-600">{{ $snapshot['customer_name'] }}</div></div>
                    <div class="col-md-6 mb-3"><small class="text-muted">{{ translate('Phone') }}</small><div class="fw-600">{{ $snapshot['customer_phone'] ?: '-' }}</div></div>
                    <div class="col-12 mb-3"><small class="text-muted">{{ translate('Delivery Address') }}</small><div>{{ $snapshot['address'] ?: '-' }}</div></div>
                    <div class="col-md-6 mb-3"><small class="text-muted">{{ translate('Branch') }}</small><div>{{ $snapshot['branch'] ?: translate('Default / unified') }}</div></div>
                    <div class="col-md-6 mb-3"><small class="text-muted">{{ translate('Delivery Employee') }}</small><div>{{ $snapshot['delivery_user'] ?: translate('Unassigned') }}</div></div>
                </div>
                <div class="alert alert-info mb-0">{{ translate('This page intentionally excludes product cost, profit, supplier balances, and accounting reports.') }}</div>
            </div>
        </div>

        @if($delivery->cod_amount !== null)
        <div class="card">
            <div class="card-header"><h5 class="mb-0 h6">{{ translate('Cash on Delivery') }}</h5></div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4"><small class="text-muted">{{ translate('Amount to collect') }}</small><div class="fs-18 fw-700">{{ coremarket_money($delivery->cod_amount) }}</div></div>
                    <div class="col-md-4"><small class="text-muted">{{ translate('Recorded collection') }}</small><div class="fs-18 fw-700">{{ coremarket_money($delivery->cod_collected_amount) }}</div></div>
                    <div class="col-md-4"><small class="text-muted">{{ translate('COD status') }}</small><div>{{ translate(str_replace('_', ' ', $delivery->cod_collection_status)) }}</div></div>
                </div>
                @if($canCollectCod)
                <hr>
                <form method="POST" action="{{ route('operations.deliveries.cod', $delivery) }}" class="form-inline">
                    @csrf
                    <input type="number" step="0.01" min="0" max="{{ $delivery->cod_amount }}" name="cod_collected_amount" value="{{ old('cod_collected_amount', $delivery->cod_collected_amount) }}" class="form-control form-control-sm mr-2" required>
                    <button class="btn btn-soft-primary btn-sm">{{ translate('Record COD') }}</button>
                </form>
                @endif
                <p class="small text-muted mt-3 mb-0">{{ translate('COD collection records money held by the delivery employee. Cashbox posting requires a separate authorized settlement.') }}</p>
            </div>
        </div>

        @if($canViewCodSummary)
        <div class="card">
            <div class="card-header"><h5 class="mb-0 h6">{{ translate('COD Settlement') }}</h5></div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4"><small class="text-muted">{{ translate('Collected') }}</small><div class="fs-18 fw-700">{{ coremarket_money($codSettlement['collected_amount']) }}</div></div>
                    <div class="col-md-4"><small class="text-muted">{{ translate('Settled') }}</small><div class="fs-18 fw-700">{{ coremarket_money($codSettlement['settled_amount']) }}</div></div>
                    <div class="col-md-4"><small class="text-muted">{{ translate('Remaining') }}</small><div class="fs-18 fw-700">{{ coremarket_money($codSettlement['remaining_amount']) }}</div></div>
                </div>
                <p class="small text-muted mt-2">{{ translate(str_replace('_', ' ', $codSettlement['status'])) }}</p>

                @if($canSettleCod && $codSettlement['remaining_amount'] > 0)
                    @if($openCashierShifts->isEmpty())
                        <div class="alert alert-warning mb-0">{{ translate('No open cashbox shift available.') }}</div>
                    @else
                        <hr>
                        @error('settlement')<div class="alert alert-danger">{{ $message }}</div>@enderror
                        <form method="POST" action="{{ route('operations.deliveries.cod-settlements.store', $delivery) }}">
                            @csrf
                            <input type="hidden" name="settlement_request_key" value="{{ old('settlement_request_key', $settlementRequestKey) }}">
                            <div class="form-row">
                                <div class="col-md-4 form-group">
                                    <label>{{ translate('Amount') }}</label>
                                    <input type="number" step="0.01" min="0.01" max="{{ $codSettlement['remaining_amount'] }}" name="amount" value="{{ old('amount', $codSettlement['remaining_amount']) }}" class="form-control" required>
                                </div>
                                <div class="col-md-8 form-group">
                                    <label>{{ translate('Open cashbox shift') }}</label>
                                    <select name="cashier_shift_id" class="form-control" required>
                                        @foreach($openCashierShifts as $shift)
                                            <option value="{{ $shift->id }}" @selected((int) old('cashier_shift_id') === $shift->id)>
                                                {{ $shift->cashbox?->name ?: translate('Cashbox') }} - #{{ $shift->id }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>{{ translate('Notes') }}</label>
                                <textarea name="notes" rows="2" class="form-control">{{ old('notes') }}</textarea>
                            </div>
                            <button class="btn btn-primary btn-sm">{{ translate('Receive COD / Settle COD') }}</button>
                        </form>
                    @endif
                @endif
                <p class="small text-muted mt-3 mb-0">{{ translate('A posted settlement creates one cash-in movement. It does not change the order payment status or create an accounting journal.') }}</p>
            </div>
        </div>
        @endif
        @endif

        <div class="card">
            <div class="card-header"><h5 class="mb-0 h6">{{ translate('Delivery Timeline') }}</h5></div>
            <div class="card-body">
                @forelse($delivery->events as $event)
                    <div class="border-bottom pb-2 mb-2">
                        <strong>{{ translate(str_replace('_', ' ', $event->new_status)) }}</strong>
                        <small class="text-muted ml-2">{{ optional($event->created_at)->format('Y-m-d H:i') }}</small>
                        @if($event->user)<small class="text-muted">- {{ $event->user->name }}</small>@endif
                        @if($event->notes)<div class="small mt-1">{{ $event->notes }}</div>@endif
                    </div>
                @empty
                    <p class="text-muted mb-0">{{ translate('No delivery events yet.') }}</p>
                @endforelse
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        @if($canAssign)
        <div class="card">
            <div class="card-header"><h5 class="mb-0 h6">{{ translate('Assign Delivery Employee') }}</h5></div>
            <div class="card-body">
                <form method="POST" action="{{ route('operations.deliveries.assign', $delivery) }}">
                    @csrf
                    <select name="delivery_user_id" class="form-control mb-3" required>
                        <option value="">{{ translate('Select delivery employee') }}</option>
                        @foreach($deliveryUsers as $employee)<option value="{{ $employee->id }}" @selected($delivery->delivery_user_id === $employee->id)>{{ $employee->name }}</option>@endforeach
                    </select>
                    <button class="btn btn-primary btn-sm">{{ translate('Assign') }}</button>
                </form>
            </div>
        </div>
        @endif

        @if($canUpdateStatus && $nextStatuses !== [])
        <div class="card">
            <div class="card-header"><h5 class="mb-0 h6">{{ translate('Update Status') }}</h5></div>
            <div class="card-body">
                <form method="POST" action="{{ route('operations.deliveries.status', $delivery) }}">
                    @csrf
                    @method('PATCH')
                    <select name="status" class="form-control mb-3" required>
                        @foreach($nextStatuses as $status)<option value="{{ $status }}">{{ translate(str_replace('_', ' ', ucfirst($status))) }}</option>@endforeach
                    </select>
                    <textarea name="notes" class="form-control mb-3" rows="3" placeholder="{{ translate('Notes / failure reason') }}"></textarea>
                    <button class="btn btn-primary btn-sm">{{ translate('Update Status') }}</button>
                </form>
            </div>
        </div>
        @endif
    </div>
</div>
@endsection
