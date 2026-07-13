@extends('backend.layouts.app')
@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3"><h5 class="mb-0 h6">{{ translate('Accounting Summary') }}</h5></div><div class="card"><div class="card-body"><table class="table"><tbody>@foreach(['sales_revenue'=>'Revenue','cogs'=>'COGS','gross_profit'=>'Gross profit','sales_returns_impact'=>'Sales returns impact','purchase_cost_received'=>'Purchase cost received','expenses'=>'Expenses','net_lite_profit'=>'Net lite profit','unknown_cost_events'=>'Unknown cost events'] as $key=>$label)<tr><th>{{ translate($label) }}</th><td>{{ $summary[$key] }}</td></tr>@endforeach</tbody></table><div class="alert alert-warning mb-0">{{ translate('This is an operational summary, not a final tax/accounting statement.') }}</div></div></div>
@endsection
