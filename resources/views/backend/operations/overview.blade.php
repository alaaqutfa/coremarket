@extends('backend.layouts.app')
@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3"><h5 class="mb-0 h6">{{ translate('Operations Overview') }}</h5></div>
<div class="row gutters-10">
@foreach(['Inventory movements'=>$movementCount,'Products with recent movement'=>$recentProductCount,'Open purchase orders'=>$openPurchaseOrders,'Active sales returns'=>$activeSalesReturns,'Expenses this month'=>$monthExpenses,'Gross profit'=>$summary['gross_profit'],'Unknown cost events'=>$summary['unknown_cost_events']] as $label => $value)
<div class="col-md-3 mb-3"><div class="card"><div class="card-body"><div class="fs-12 text-secondary">{{ translate($label) }}</div><div class="fs-20 fw-700">{{ in_array($label, ['Expenses this month', 'Gross profit'], true) ? coremarket_money($value) : coremarket_number($value, 0) }}</div></div></div></div>
@endforeach
</div>
<div class="alert alert-info">{{ translate('This is an operational summary, not a final tax/accounting statement.') }}</div>
@endsection
