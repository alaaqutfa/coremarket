@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3">
    <h5 class="mb-0 h6">{{ $adjustmentType === 'opening_stock' ? translate('Create Opening Stock') : translate('Create Stock Adjustment') }}</h5>
</div>

<div class="card">
    <div class="card-body">
        @if($adjustmentType === 'opening_stock' && !$policy['setup_mode_enabled'])
            <div class="alert alert-warning">{{ translate('Setup mode is disabled. Only an authorized manager can post opening stock.') }}</div>
        @endif
        <form method="POST" action="{{ route('operations.inventory.adjustments.store') }}">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            @if($adjustmentType === 'opening_stock')
                <input type="hidden" name="adjustment_type" value="opening_stock">
            @endif
            <div class="row">
                @if($adjustmentType !== 'opening_stock')
                <div class="col-md-2 form-group">
                    <label>{{ translate('Adjustment type') }}</label>
                    <select class="form-control" name="adjustment_type" required>
                        @foreach(['stock_adjustment', 'damage', 'loss', 'theft', 'internal_use', 'correction', 'supplier_bonus', 'expired_goods', 'sample', 'emergency_adjustment'] as $type)
                            @if($type !== 'emergency_adjustment' || auth()->user()?->user_type === 'admin' || auth()->user()?->can('inventory.adjustments.emergency'))
                                <option value="{{ $type }}" @selected(old('adjustment_type', $adjustmentType) === $type)>{{ translate(ucwords(str_replace('_', ' ', $type))) }}</option>
                            @endif
                        @endforeach
                    </select>
                </div>
                @endif
                <div class="{{ $adjustmentType === 'opening_stock' ? 'col-md-5' : 'col-md-4' }} form-group">
                    <label>{{ translate('Product / Variant') }}</label>
                    <select class="form-control aiz-selectpicker" name="product_stock_id" data-live-search="true" required>
                        <option value="">{{ translate('Select product') }}</option>
                        @foreach($stocks as $stock)
                            <option value="{{ $stock->id }}" @selected((int) old('product_stock_id', $selectedStockId) === $stock->id)>
                                {{ $stock->product?->name }} {{ $stock->variant ? ' - '.$stock->variant : '' }} | {{ $stock->sku ?: $stock->barcode }} | {{ coremarket_quantity($stock->qty) }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="{{ $adjustmentType === 'opening_stock' ? 'col-md-3' : 'col-md-2' }} form-group">
                    <label>{{ $adjustmentType === 'opening_stock' ? translate('Opening quantity') : translate('Quantity change (+/-)') }}</label>
                    <input class="form-control" type="number" step="0.000001" name="quantity_change" value="{{ old('quantity_change') }}" required>
                </div>
                <div class="col-md-2 form-group">
                    <label>{{ translate('Unit cost') }}</label>
                    <input class="form-control" type="number" min="0" step="0.01" name="unit_cost" value="{{ old('unit_cost') }}">
                </div>
                <div class="col-md-2 form-group">
                    <label>{{ translate('Branch context') }}</label>
                    <select class="form-control" name="branch_id">
                        <option value="">{{ translate('Default / unified') }}</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" @selected((int) old('branch_id') === $branch->id)>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="col-md-4 form-group">
                    <label>{{ translate('Reason') }}</label>
                    <input class="form-control" name="reason" value="{{ old('reason') }}" required>
                </div>
                <div class="col-md-8 form-group">
                    <label>{{ translate('Notes') }}</label>
                    <textarea class="form-control" name="notes">{{ old('notes') }}</textarea>
                </div>
            </div>
            <div class="alert alert-info">
                {{ translate('Creating this document does not change stock. Stock changes only after approval and posting.') }}
                {{ translate('Branch is context only; branch-specific inventory is not enabled.') }}
            </div>
            <button class="btn btn-primary">{{ translate('Create Draft') }}</button>
        </form>
    </div>
</div>
@endsection
