@extends('backend.layouts.app')
@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3"><h5 class="mb-0 h6">{{translate('VAT Audit')}}</h5></div>
<div class="alert alert-info">{{translate('Read-only audit. It does not modify legacy tax calculations, tax rows, or checkout.') }}</div>
<div class="card"><div class="card-body"><table class="table"><tr><th>{{translate('Configured Tax Rates')}}</th><td>{{$audit['rates']}}</td></tr><tr><th>{{translate('Default Price Mode')}}</th><td>{{$audit['default_price_mode']}}</td></tr><tr><th>{{translate('Order Details Missing Snapshot')}}</th><td>{{$audit['order_details_missing_snapshot']}}</td></tr><tr><th>{{translate('Sales Return Tax Rows')}}</th><td>{{$audit['return_tax_rows']}}</td></tr><tr><th>{{translate('VAT Snapshots')}}</th><td>{{$audit['snapshots']}}</td></tr></table></div></div>
@endsection
