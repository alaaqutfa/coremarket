@extends('backend.layouts.app')
@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3"><h5 class="mb-0 h6">{{translate('General Ledger')}}</h5></div>
<div class="alert alert-info">{{translate('Only posted journal entries are included in this ledger.') }}</div>
<div class="card"><div class="card-body table-responsive"><table class="table aiz-table"><thead><tr><th>{{translate('Date')}}</th><th>{{translate('Account')}}</th><th>{{translate('Journal')}}</th><th>{{translate('Debit')}}</th><th>{{translate('Credit')}}</th><th>{{translate('Running Balance')}}</th></tr></thead><tbody>@forelse($rows as $line)<tr><td>{{$line->journalEntry?->entry_date}}</td><td>{{$line->account?->code}} {{$line->account?->name}}</td><td><a href="{{route('operations.accounting.journals.show',$line->journal_entry_id)}}">{{$line->journalEntry?->entry_number}}</a></td><td>{{$line->debit}}</td><td>{{$line->credit}}</td><td>{{number_format($line->running_balance,2)}}</td></tr>@empty<tr><td colspan="6" class="text-center text-muted">{{translate('No posted ledger lines found.')}}</td></tr>@endforelse</tbody></table></div></div>
@endsection
