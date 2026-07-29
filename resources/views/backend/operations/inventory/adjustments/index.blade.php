@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3 d-flex justify-content-between align-items-center">
    <h5 class="mb-0 h6">{{ translate('Inventory Adjustments') }}</h5>
    <div>
        @if(auth()->user()?->user_type === 'admin' || auth()->user()?->can('inventory.opening_stock.create'))
            <a class="btn btn-soft-info" href="{{ route('operations.inventory.opening-stock.create') }}">{{ translate('Opening Stock') }}</a>
        @endif
        @if(auth()->user()?->user_type === 'admin' || auth()->user()?->can('inventory.adjustments.create'))
            <a class="btn btn-primary" href="{{ route('operations.inventory.adjustments.create') }}">{{ translate('New Adjustment') }}</a>
        @endif
    </div>
</div>

<div class="card">
    <div class="card-body">
        <form class="row mb-3">
            <div class="col-md-4">
                <select class="form-control" name="status">
                    <option value="">{{ translate('All statuses') }}</option>
                    @foreach(['draft', 'pending_approval', 'approved', 'posted', 'rejected', 'cancelled'] as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ translate(ucwords(str_replace('_', ' ', $status))) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <select class="form-control" name="adjustment_type">
                    <option value="">{{ translate('All types') }}</option>
                    @foreach(\App\Services\CoreMarketInventoryAdjustmentService::TYPES as $type)
                        <option value="{{ $type }}" @selected(request('adjustment_type') === $type)>{{ translate(ucwords(str_replace('_', ' ', $type))) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2"><button class="btn btn-soft-primary">{{ translate('Filter') }}</button></div>
        </form>
        <div class="table-responsive">
            <table class="table table-bordered">
                <thead><tr><th>{{ translate('Reference') }}</th><th>{{ translate('Type') }}</th><th>{{ translate('Status') }}</th><th>{{ translate('Reason') }}</th><th>{{ translate('Created by') }}</th><th></th></tr></thead>
                <tbody>
                @forelse($documents as $document)
                    <tr>
                        <td>{{ $document->reference_no }}</td>
                        <td>{{ translate(ucwords(str_replace('_', ' ', $document->adjustment_type))) }}</td>
                        <td>{{ translate(ucwords(str_replace('_', ' ', $document->status))) }}</td>
                        <td>{{ $document->reason ?: '-' }}</td>
                        <td>{{ $document->creator?->name ?: '-' }}</td>
                        <td><a class="btn btn-soft-primary btn-sm" href="{{ route('operations.inventory.adjustments.show', $document) }}">{{ translate('View') }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted">{{ translate('No inventory documents found.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $documents->links() }}
    </div>
</div>
@endsection
