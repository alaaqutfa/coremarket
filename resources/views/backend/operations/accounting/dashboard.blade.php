@extends('backend.layouts.app')
@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3"><h5 class="mb-0 h6">{{ translate('Accounting Dashboard') }}</h5></div>
<div class="alert alert-info">{{ translate('Operational accounting overview. It is not a final tax statement or VAT filing.') }}</div>
<div class="row gutters-10">@foreach(['accounts'=>'Accounts','posted_journals'=>'Posted Journals','draft_journals'=>'Draft Journals','unbalanced_journals'=>'Unbalanced Journals','events_without_journal'=>'Events Without Journal','vat_snapshots'=>'VAT Snapshots','sales_revenue'=>'Revenue','cogs'=>'COGS','gross_profit'=>'Gross Profit','expenses'=>'Expenses','net_lite_profit'=>'Net Profit Lite','unknown_cost_events'=>'Unknown Cost Events'] as $key=>$label)<div class="col-md-3 mb-3"><div class="card h-100"><div class="card-body"><small class="text-muted">{{ translate($label) }}</small><div class="h4 mb-0">{{ number_format((float) $stats[$key], 2) }}</div></div></div></div>@endforeach</div>
@endsection
