@extends('backend.layouts.app')
@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3"><h5 class="mb-0 h6">{{ $account->code }} - {{ $account->name }}</h5></div>
<div class="card"><div class="card-body table-responsive"><table class="table aiz-table"><thead><tr><th>{{translate('Date')}}</th><th>{{translate('Journal')}}</th><th>{{translate('Description')}}</th><th>{{translate('Debit')}}</th><th>{{translate('Credit')}}</th></tr></thead><tbody>@forelse($lines as $line)<tr><td>{{$line->journalEntry?->entry_date}}</td><td><a href="{{route('operations.accounting.journals.show',$line->journal_entry_id)}}">{{$line->journalEntry?->entry_number}}</a></td><td>{{$line->description}}</td><td>{{$line->debit}}</td><td>{{$line->credit}}</td></tr>@empty<tr><td colspan="5" class="text-center text-muted">{{translate('No posted lines yet.')}}</td></tr>@endforelse</tbody></table><div class="aiz-pagination">{{$lines->links()}}</div></div></div>
@endsection
