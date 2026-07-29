@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3"><h5 class="mb-0">{{ translate('Warranty & Serial Tracking') }}</h5></div>

<div class="card"><div class="card-body">
    <form class="row gutters-10" method="GET">
        <div class="col-md-9"><label>{{ translate('Serial or IMEI') }}</label><input class="form-control" name="identity" value="{{ $identity }}"></div>
        <div class="col-md-3 align-self-end"><button class="btn btn-primary btn-block">{{ translate('Find Unit') }}</button></div>
    </form>
    @if($identity !== '')
        <div class="alert {{ $serialUnit ? 'alert-info' : 'alert-warning' }} mt-3 mb-0">
            @if($serialUnit)
                {{ $serialUnit->product?->name }} · {{ $serialUnit->serial_number ?: $serialUnit->imei_1 }} · {{ translate(ucfirst(str_replace('_', ' ', $serialUnit->status))) }}
            @else
                {{ translate('No serial unit matched this identity.') }}
            @endif
        </div>
    @endif
</div></div>

@if(auth()->user()->user_type === 'admin' || auth()->user()->can('warranty.policies.manage'))
<div class="card"><div class="card-header"><h6 class="mb-0">{{ translate('Create Warranty Policy') }}</h6></div><div class="card-body">
    <form method="POST" action="{{ route('operations.warranty.policies.store') }}">@csrf
        <div class="row gutters-10">
            <div class="col-md-4"><label>{{ translate('Product') }}</label><select class="form-control aiz-selectpicker" name="product_id" data-live-search="true" required><option value="">{{ translate('Select') }}</option>@foreach($products as $product)<option value="{{ $product->id }}">{{ $product->name }}</option>@endforeach</select></div>
            <div class="col-md-4"><label>{{ translate('Variant') }}</label><select class="form-control aiz-selectpicker" name="product_stock_id" data-live-search="true"><option value="">{{ translate('Product default') }}</option>@foreach($stocks as $stock)<option value="{{ $stock->id }}">{{ $stock->product?->name }} · {{ $stock->variant ?: '-' }} · {{ $stock->sku }}</option>@endforeach</select></div>
            <div class="col-md-4"><label>{{ translate('Policy Name') }}</label><input class="form-control" name="name" required></div>
            <div class="col-md-3 mt-3"><label>{{ translate('Warranty Months') }}</label><input class="form-control" type="number" min="0" max="240" name="warranty_months" value="12" required></div>
            <div class="col-md-3 mt-3"><label>{{ translate('Status') }}</label><select class="form-control" name="status"><option value="active">{{ translate('Active') }}</option><option value="inactive">{{ translate('Inactive') }}</option></select></div>
            <div class="col-md-3 mt-3"><label class="d-block">{{ translate('Tracking') }}</label><label><input type="checkbox" name="serial_tracking_enabled" value="1"> {{ translate('Serial') }}</label> <label class="ml-3"><input type="checkbox" name="imei_tracking_enabled" value="1"> {{ translate('IMEI') }}</label></div>
            <div class="col-md-12 mt-3"><label>{{ translate('Coverage Notes') }}</label><textarea class="form-control" name="coverage_notes" rows="2"></textarea></div>
            <div class="col-md-12 mt-3"><button class="btn btn-primary">{{ translate('Create Policy') }}</button></div>
        </div>
    </form>
</div></div>
@endif

@if($serialUnit && (auth()->user()->user_type === 'admin' || auth()->user()->can('warranty.claims.create')))
<div class="card"><div class="card-header"><h6 class="mb-0">{{ translate('Open Warranty Claim') }}</h6></div><div class="card-body">
    <form method="POST" action="{{ route('operations.warranty.claims.store') }}">@csrf
        <input type="hidden" name="product_serial_unit_id" value="{{ $serialUnit->id }}">
        <label>{{ translate('Issue Description') }}</label><textarea class="form-control" name="issue_description" rows="3" required></textarea>
        <button class="btn btn-primary mt-3">{{ translate('Create Claim') }}</button>
    </form>
</div></div>
@endif

<div class="row">
<div class="col-lg-7"><div class="card"><div class="card-header"><h6 class="mb-0">{{ translate('Warranty Claims') }}</h6></div><div class="card-body table-responsive"><table class="table table-bordered"><thead><tr><th>#</th><th>{{ translate('Product') }}</th><th>{{ translate('Identity') }}</th><th>{{ translate('Customer') }}</th><th>{{ translate('Status') }}</th><th></th></tr></thead><tbody>@forelse($claims as $claim)<tr><td>{{ $claim->id }}</td><td>{{ $claim->product?->name }}</td><td>{{ $claim->serialUnit?->serial_number ?: $claim->serialUnit?->imei_1 }}</td><td>{{ $claim->customer?->name ?: '-' }}</td><td>{{ translate(ucfirst(str_replace('_', ' ', $claim->status))) }}</td><td><a class="btn btn-soft-primary btn-sm" href="{{ route('operations.warranty.claims.show', $claim) }}">{{ translate('View') }}</a></td></tr>@empty<tr><td colspan="6" class="text-center text-muted">{{ translate('No warranty claims yet.') }}</td></tr>@endforelse</tbody></table>{{ $claims->withQueryString()->links() }}</div></div></div>
<div class="col-lg-5"><div class="card"><div class="card-header"><h6 class="mb-0">{{ translate('Warranty Policies') }}</h6></div><div class="card-body table-responsive"><table class="table table-bordered"><thead><tr><th>{{ translate('Name') }}</th><th>{{ translate('Product / Variant') }}</th><th>{{ translate('Months') }}</th><th>{{ translate('Status') }}</th></tr></thead><tbody>@forelse($policies as $policy)<tr><td>{{ $policy->name }}</td><td>{{ $policy->product?->name }} {{ $policy->productStock?->variant }}</td><td>{{ $policy->warranty_months }}</td><td>{{ translate(ucfirst($policy->status)) }}</td></tr>@empty<tr><td colspan="4" class="text-center text-muted">{{ translate('No policies yet.') }}</td></tr>@endforelse</tbody></table></div></div></div>
</div>
@endsection
