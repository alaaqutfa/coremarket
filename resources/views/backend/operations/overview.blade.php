@extends('backend.layouts.app')
@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3"><h5 class="mb-0 h6">{{ translate('Operations Overview') }}</h5></div>
@if(!empty($quickActions))
<div class="card mb-3">
    <div class="card-header"><h6 class="mb-0">{{ translate('Quick Actions') }}</h6></div>
    <div class="card-body">
        <div class="row gutters-10">
            @foreach($quickActions as $action)
            <div class="col-xl-3 col-md-4 col-sm-6 mb-3">
                <a href="{{ $action['url'] }}" class="d-block border rounded p-3 h-100 text-reset">
                    <div class="fw-700 text-primary mb-1">{{ translate($action['label']) }}</div>
                    <div class="fs-12 text-secondary">{{ translate($action['description']) }}</div>
                </a>
            </div>
            @endforeach
        </div>
    </div>
</div>
@endif
<div class="row gutters-10">
@foreach(['Inventory movements'=>$movementCount,'Products with recent movement'=>$recentProductCount,'Open purchase orders'=>$openPurchaseOrders,'Active sales returns'=>$activeSalesReturns,'Expenses this month'=>$monthExpenses,'Gross profit'=>$summary['gross_profit'],'Unknown cost events'=>$summary['unknown_cost_events']] as $label => $value)
<div class="col-md-3 mb-3"><div class="card"><div class="card-body"><div class="fs-12 text-secondary">{{ translate($label) }}</div><div class="fs-20 fw-700">{{ in_array($label, ['Expenses this month', 'Gross profit'], true) ? coremarket_money($value) : coremarket_number($value, 0) }}</div></div></div></div>
@endforeach
</div>
<div class="alert alert-info">{{ translate('This is an operational summary, not a final tax/accounting statement.') }}</div>
@endsection
