@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3 d-flex justify-content-between align-items-center">
    <h5 class="mb-0 h6">{{ translate('Stock Counts') }}</h5>
    @if(auth()->user()?->user_type === 'admin' || auth()->user()?->can('inventory.stock_counts.create'))
        <a class="btn btn-primary" href="{{ route('operations.inventory.stock-counts.create') }}">{{ translate('New Stock Count') }}</a>
    @endif
</div>
<div class="card"><div class="card-body">
    <div class="table-responsive"><table class="table table-bordered">
        <thead><tr><th>{{ translate('Reference') }}</th><th>{{ translate('Status') }}</th><th>{{ translate('Branch context') }}</th><th>{{ translate('Counted at') }}</th><th></th></tr></thead>
        <tbody>
        @forelse($stockCounts as $count)
            <tr><td>{{ $count->reference_no }}</td><td>{{ translate(ucwords(str_replace('_', ' ', $count->status))) }}</td><td>{{ $count->branch?->name ?: translate('Unified inventory') }}</td><td>{{ $count->counted_at?->format('Y-m-d H:i') }}</td><td><a class="btn btn-soft-primary btn-sm" href="{{ route('operations.inventory.stock-counts.show', $count) }}">{{ translate('View') }}</a></td></tr>
        @empty
            <tr><td colspan="5" class="text-center text-muted">{{ translate('No stock counts found.') }}</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $stockCounts->links() }}
</div></div>
@endsection
