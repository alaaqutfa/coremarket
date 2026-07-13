@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3"><div class="row"><div class="col"><h5 class="mb-0 h6">{{ translate('Purchase Orders') }}</h5></div>@can('purchase_orders.create')<div class="col text-right"><a class="btn btn-primary" href="{{ route('operations.purchase-orders.create') }}">{{ translate('Create Purchase Order') }}</a></div>@endcan</div></div>
<div class="card"><div class="card-body">
    <form method="GET" class="row gutters-10 mb-3">
        <div class="col-md-3"><select class="form-control" name="supplier_id"><option value="">{{ translate('All suppliers') }}</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected((string) request('supplier_id') === (string) $supplier->id)>{{ $supplier->name }}</option>@endforeach</select></div>
        <div class="col-md-2"><select class="form-control" name="status"><option value="">{{ translate('All statuses') }}</option>@foreach(['draft', 'ordered', 'partially_received', 'received', 'cancelled'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ translate(ucfirst(str_replace('_', ' ', $status))) }}</option>@endforeach</select></div>
        <div class="col-md-2"><input type="date" class="form-control" name="from" value="{{ request('from') }}"></div><div class="col-md-2"><input type="date" class="form-control" name="to" value="{{ request('to') }}"></div><div class="col-md-3"><button class="btn btn-soft-primary">{{ translate('Filter') }}</button></div>
    </form>
    <div class="table-responsive"><table class="table aiz-table mb-0"><thead><tr><th>{{ translate('Number') }}</th><th>{{ translate('Supplier') }}</th><th>{{ translate('Status') }}</th><th>{{ translate('Ordered at') }}</th><th>{{ translate('Progress') }}</th><th>{{ translate('Total Cost') }}</th><th></th></tr></thead><tbody>
    @forelse($purchaseOrders as $order)@php($ordered = (float) $order->items->sum('quantity_ordered'))@php($received = (float) $order->items->sum('quantity_received'))<tr>
        <td>{{ $order->purchase_number }}</td><td>{{ $order->supplier?->name ?: '-' }}</td><td><span class="badge badge-info">{{ translate(ucfirst(str_replace('_', ' ', $order->status))) }}</span></td><td>{{ optional($order->ordered_at)->format('Y-m-d') ?: '-' }}</td><td>{{ $received }} / {{ $ordered }}</td><td>{{ $order->total_amount }} {{ $order->currency }}</td><td class="text-right"><a class="btn btn-soft-primary btn-sm" href="{{ route('operations.purchase-orders.show', $order) }}">{{ translate('View') }}</a></td>
    </tr>@empty<tr><td colspan="7" class="text-center text-muted">{{ translate('No purchase orders found.') }}</td></tr>@endforelse
    </tbody></table></div><div class="aiz-pagination">{{ $purchaseOrders->links() }}</div>
</div></div>
@endsection
