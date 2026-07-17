@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3 d-print-none">
    <div class="row"><div class="col"><h5 class="mb-0 h6">{{ translate('POS Receipt') }}</h5></div><div class="col-auto"><button class="btn btn-primary" onclick="window.print()">{{ translate('Print') }}</button></div></div>
</div>
<div class="card mx-auto" style="max-width: 760px;">
    <div class="card-body p-4">
        <div class="text-center border-bottom pb-3 mb-3"><h4 class="mb-1">{{ get_setting('site_name') }}</h4><div class="text-muted">{{ translate('Cash sale receipt') }}</div><strong>{{ $order->pos_receipt_number }}</strong></div>
        <div class="row small mb-3">
            <div class="col-6">
                <div>{{ translate('Order') }}: {{ $order->code }}</div>
                <div>{{ translate('Date') }}: {{ optional($order->created_at)->format('Y-m-d H:i') }}</div>
                @if ($receipt['customer'])
                    <div>{{ translate('Customer') }}: {{ $receipt['customer']['name'] }}</div>
                    @if ($receipt['customer']['phone'])
                        <div>{{ translate('Phone') }}: {{ $receipt['customer']['phone'] }}</div>
                    @endif
                @endif
            </div>
            <div class="col-6 text-right"><div>{{ translate('Cashier') }}: {{ $order->cashier?->name ?: '-' }}</div><div>{{ translate('Cashbox') }}: {{ $order->cashbox?->name ?: '-' }}</div><div>{{ translate('Shift') }}: #{{ $order->cashier_shift_id }}</div></div>
        </div>
        <table class="table table-sm"><thead><tr><th>{{ translate('Item') }}</th><th class="text-center">{{ translate('Qty') }}</th><th class="text-right">{{ translate('Amount') }}</th></tr></thead><tbody>@foreach ($order->orderDetails as $detail)<tr><td>{{ $detail->product?->name ?: translate('Product') }} @if ($detail->variation)<small class="text-muted d-block">{{ $detail->variation }}</small>@endif</td><td class="text-center">{{ $detail->quantity }}</td><td class="text-right">{{ number_format((float) $detail->price + (float) $detail->tax, 2) }}</td></tr>@endforeach</tbody></table>
        <div class="ml-auto" style="max-width: 280px;">
            <div class="d-flex justify-content-between"><span>{{ translate('Subtotal') }}</span><span>{{ number_format((float) $order->orderDetails->sum('price'), 2) }}</span></div>
            <div class="d-flex justify-content-between"><span>{{ translate('Tax') }}</span><span>{{ number_format((float) $order->orderDetails->sum('tax'), 2) }}</span></div>
            <div class="d-flex justify-content-between font-weight-bold border-top pt-2 mt-2"><span>{{ translate('Grand total') }}</span><span>{{ number_format((float) $order->grand_total, 2) }}</span></div>
            <div class="d-flex justify-content-between"><span>{{ translate('Paid') }}</span><span>{{ number_format((float) $order->paid_amount, 2) }}</span></div>
            <div class="d-flex justify-content-between"><span>{{ translate('Change') }}</span><span>{{ number_format((float) $order->change_amount, 2) }}</span></div>
            @if ($receipt['customer'] && $receipt['loyalty']['enabled'])
                <div class="border-top pt-2 mt-2 text-success">
                    <div class="d-flex justify-content-between"><span>{{ translate('Points earned') }}</span><span>{{ $receipt['loyalty']['points_earned'] }}</span></div>
                    <div class="d-flex justify-content-between"><span>{{ translate('Balance before') }}</span><span>{{ $receipt['loyalty']['balance_before'] }}</span></div>
                    <div class="d-flex justify-content-between"><span>{{ translate('Balance after') }}</span><span>{{ $receipt['loyalty']['balance_after'] }}</span></div>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
