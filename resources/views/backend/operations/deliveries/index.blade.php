@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3 d-flex align-items-center justify-content-between">
    <h1 class="h3 mb-0">{{ translate('Delivery Board') }}</h1>
    <span class="badge badge-soft-info">{{ translate('Operational COD tracking only') }}</span>
</div>

@if($canEnsure)
<div class="card mb-3">
    <div class="card-body">
        <form method="POST" action="{{ route('operations.deliveries.ensure', ['order' => '__ORDER__']) }}" class="form-inline" id="ensure-delivery-form">
            @csrf
            <label class="mr-2">{{ translate('Create delivery for order ID') }}</label>
            <input type="number" min="1" class="form-control form-control-sm mr-2" id="ensure-delivery-order-id" required>
            <button class="btn btn-primary btn-sm">{{ translate('Prepare Delivery') }}</button>
        </form>
    </div>
</div>
@endif

<div class="card">
    <div class="card-header"><h5 class="mb-0 h6">{{ translate('Deliveries') }}</h5></div>
    <div class="card-body">
        <form method="GET" class="row gutters-5 mb-3">
            <div class="col-md-2"><select name="status" class="form-control form-control-sm"><option value="">{{ translate('All statuses') }}</option>@foreach($statuses as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ translate(str_replace('_', ' ', ucfirst($status))) }}</option>@endforeach</select></div>
            @if($deliveryUsers->isNotEmpty())<div class="col-md-2"><select name="delivery_user_id" class="form-control form-control-sm"><option value="">{{ translate('All delivery staff') }}</option>@foreach($deliveryUsers as $employee)<option value="{{ $employee->id }}" @selected((int) request('delivery_user_id') === $employee->id)>{{ $employee->name }}</option>@endforeach</select></div>@endif
            <div class="col-md-2"><select name="branch_id" class="form-control form-control-sm"><option value="">{{ translate('All branches') }}</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((int) request('branch_id') === $branch->id)>{{ $branch->name }}</option>@endforeach</select></div>
            <div class="col-md-2"><select name="cod_status" class="form-control form-control-sm"><option value="">{{ translate('All COD statuses') }}</option>@foreach(['not_required','pending','collected','partially_collected','failed'] as $cod)<option value="{{ $cod }}" @selected(request('cod_status') === $cod)>{{ translate(str_replace('_', ' ', ucfirst($cod))) }}</option>@endforeach</select></div>
            <div class="col-md-1"><input type="date" name="date_from" value="{{ request('date_from') }}" class="form-control form-control-sm"></div>
            <div class="col-md-1"><input type="date" name="date_to" value="{{ request('date_to') }}" class="form-control form-control-sm"></div>
            <div class="col-md-2"><button class="btn btn-soft-primary btn-sm btn-block">{{ translate('Filter') }}</button></div>
        </form>

        <div class="table-responsive">
            <table class="table aiz-table mb-0">
                <thead><tr><th>{{ translate('Order') }}</th><th>{{ translate('Customer') }}</th><th>{{ translate('Phone / Address') }}</th><th>{{ translate('Status') }}</th><th>{{ translate('Delivery Employee') }}</th><th>{{ translate('COD') }}</th><th>{{ translate('Updated') }}</th><th></th></tr></thead>
                <tbody>
                @forelse($deliveries as $delivery)
                    @php($item = $delivery->safe_snapshot)
                    <tr>
                        <td>{{ $item['order_code'] }}</td>
                        <td>{{ $item['customer_name'] }}</td>
                        <td><div>{{ $item['customer_phone'] ?: '-' }}</div><small class="text-muted">{{ \Illuminate\Support\Str::limit($item['address'] ?: '-', 70) }}</small></td>
                        <td><span class="badge badge-soft-primary">{{ translate(str_replace('_', ' ', $item['status'])) }}</span></td>
                        <td>{{ $item['delivery_user'] ?: translate('Unassigned') }}</td>
                        <td>@if($item['cod_amount'] !== null){{ coremarket_money($item['cod_amount']) }}<br><small>{{ translate(str_replace('_', ' ', $item['cod_collection_status'])) }}</small>@else-@endif</td>
                        <td>{{ optional($item['updated_at'])->format('Y-m-d H:i') }}</td>
                        <td><a class="btn btn-soft-primary btn-icon btn-circle btn-sm" href="{{ route('operations.deliveries.show', $delivery) }}" title="{{ translate('View') }}">&#8594;</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted">{{ translate('No delivery records found.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $deliveries->links() }}</div>
    </div>
</div>
@endsection

@section('script')
@if($canEnsure)
<script>
    document.getElementById('ensure-delivery-form').addEventListener('submit', function (event) {
        const orderId = document.getElementById('ensure-delivery-order-id').value;
        if (!orderId) {
            event.preventDefault();
            return;
        }
        this.action = this.action.replace('__ORDER__', encodeURIComponent(orderId));
    });
</script>
@endif
@endsection
