@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3"><h5 class="mb-0">{{ translate('Warranty Claim') }} #{{ $warrantyClaim->id }}</h5></div>
<div class="card"><div class="card-body">
    <div class="row">
        <div class="col-md-4"><strong>{{ translate('Product') }}:</strong> {{ $warrantyClaim->product?->name }}</div>
        <div class="col-md-4"><strong>{{ translate('Serial / IMEI') }}:</strong> {{ $warrantyClaim->serialUnit?->serial_number ?: $warrantyClaim->serialUnit?->imei_1 }}</div>
        <div class="col-md-4"><strong>{{ translate('Branch') }}:</strong> {{ $warrantyClaim->serialUnit?->branch?->name ?: '-' }}</div>
        <div class="col-md-4 mt-3"><strong>{{ translate('Customer') }}:</strong> {{ $warrantyClaim->customer?->name ?: '-' }}</div>
        <div class="col-md-4 mt-3"><strong>{{ translate('Order') }}:</strong> {{ $warrantyClaim->order_id ? '#'.$warrantyClaim->order_id : '-' }}</div>
        <div class="col-md-4 mt-3"><strong>{{ translate('Warranty Expires') }}:</strong> {{ optional($warrantyClaim->serialUnit?->warranty_expires_at)->format('Y-m-d') ?: '-' }}</div>
        <div class="col-md-12 mt-3"><strong>{{ translate('Issue') }}:</strong><div>{{ $warrantyClaim->issue_description ?: '-' }}</div></div>
    </div>
</div></div>
@if(auth()->user()->user_type === 'admin' || auth()->user()->can('warranty.claims.manage'))
<div class="card"><div class="card-header"><h6 class="mb-0">{{ translate('Update Status') }}</h6></div><div class="card-body">
    <form method="POST" action="{{ route('operations.warranty.claims.update', $warrantyClaim) }}">@csrf @method('PATCH')
        <div class="row"><div class="col-md-4"><select class="form-control" name="status">@foreach(\App\Services\CoreMarketWarrantyService::STATUSES as $status)<option value="{{ $status }}" @selected($warrantyClaim->status === $status)>{{ translate(ucfirst(str_replace('_', ' ', $status))) }}</option>@endforeach</select></div><div class="col-md-6"><textarea class="form-control" name="resolution_notes" placeholder="{{ translate('Resolution notes') }}">{{ $warrantyClaim->resolution_notes }}</textarea></div><div class="col-md-2"><button class="btn btn-primary btn-block">{{ translate('Update') }}</button></div></div>
    </form>
</div></div>
@endif
@endsection
