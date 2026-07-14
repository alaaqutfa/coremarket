@extends('backend.layouts.app')
@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3"><h5 class="mb-0 h6">{{translate('Trial Balance')}}</h5></div>
@if(abs($total_debit-$total_credit)>0.00001)<div class="alert alert-warning">{{translate('Posted journal totals are not balanced. Review the journal entries before relying on this report.') }}</div>@endif
<div class="card"><div class="card-body table-responsive"><table class="table aiz-table"><thead><tr><th>{{translate('Account')}}</th><th>{{translate('Type')}}</th><th>{{translate('Debit')}}</th><th>{{translate('Credit')}}</th></tr></thead><tbody>@foreach($rows as $row)<tr><td>{{$row['account']?->code}} {{$row['account']?->name}}</td><td>{{$row['account']?->type}}</td><td>{{number_format($row['debit'],2)}}</td><td>{{number_format($row['credit'],2)}}</td></tr>@endforeach</tbody><tfoot><tr><th colspan="2">{{translate('Totals')}}</th><th>{{number_format($total_debit,2)}}</th><th>{{number_format($total_credit,2)}}</th></tr></tfoot></table></div></div>
@endsection
