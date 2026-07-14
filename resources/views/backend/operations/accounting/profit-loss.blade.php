@extends('backend.layouts.app')
@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3"><h5 class="mb-0 h6">{{translate('Profit & Loss')}}</h5></div>
@if(!$journals_complete)<div class="alert alert-warning">{{translate('Some values are based on incomplete journals. Operational accounting events may still need posting.') }}</div>@endif
<div class="card"><div class="card-body"><table class="table"><tr><th>{{translate('Sales Revenue')}}</th><td>{{number_format($revenue,2)}}</td></tr><tr><th>{{translate('Cost of Goods Sold')}}</th><td>{{number_format($cogs,2)}}</td></tr><tr><th>{{translate('Gross Profit')}}</th><td>{{number_format($gross_profit,2)}}</td></tr><tr><th>{{translate('Operating Expenses')}}</th><td>{{number_format($expenses,2)}}</td></tr><tr class="font-weight-bold"><th>{{translate('Net Profit Lite')}}</th><td>{{number_format($net_profit,2)}}</td></tr></table></div></div>
@endsection
