@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3">
    <h5 class="mb-0 h6">{{ translate('Inventory Policy') }}</h5>
</div>

<div class="card">
    <div class="card-body">
        <form method="POST" action="{{ route('operations.inventory.policy.update') }}">
            @csrf
            <input type="hidden" name="strict_inventory_mode" value="0">
            <input type="hidden" name="allow_negative_stock" value="0">
            @foreach(['setup_mode_enabled', 'opening_stock_enabled', 'adjustments_enabled', 'adjustment_requires_approval', 'stock_counts_enabled', 'emergency_adjustment_enabled', 'branch_inventory_enabled', 'serial_tracking_enabled', 'imei_tracking_enabled', 'warranty_tracking_enabled', 'advanced_variants_enabled'] as $setting)
                <input type="hidden" name="{{ $setting }}" value="0">
            @endforeach

            <div class="form-group">
                <label class="aiz-switch aiz-switch-success mb-2">
                    <input type="checkbox" name="strict_inventory_mode" value="1" @checked($policy['strict_inventory_mode'])>
                    <span class="slider round"></span>
                </label>
                <strong class="ml-2">{{ translate('Strict inventory mode') }}</strong>
                <p class="text-muted mb-0">{{ translate('Stock entries should come from purchase receipts or authorized adjustments. Product edits preserve existing quantities.') }}</p>
            </div>

            <hr>

            @foreach([
                'setup_mode_enabled' => ['Setup mode', 'Allows controlled opening stock during initial store setup. Disable it after Go-Live.'],
                'opening_stock_enabled' => ['Opening stock documents', 'Allows documented initial stock; product creation itself always starts at zero.'],
                'adjustments_enabled' => ['Stock adjustment documents', 'Allows damage, loss, correction, samples, bonuses, and other documented adjustments.'],
                'adjustment_requires_approval' => ['Adjustment approval required', 'Draft adjustments must be reviewed before they can be posted.'],
                'stock_counts_enabled' => ['Stock counts', 'Allows cycle counts whose variance is posted through an adjustment document.'],
                'emergency_adjustment_enabled' => ['Emergency adjustments', 'Manager-only emergency documents. Keep disabled unless operationally required.'],
                'branch_inventory_enabled' => ['Branch inventory', 'Uses branch balances as availability source and keeps product stock as an aggregate mirror. Run the initialization command before enabling.'],
                'serial_tracking_enabled' => ['Serial tracking', 'Requires tracked variants to receive and sell one serial unit per quantity.'],
                'imei_tracking_enabled' => ['IMEI tracking', 'Requires IMEI 1 on each newly received serialized unit.'],
                'warranty_tracking_enabled' => ['Warranty tracking', 'Enables warranty policies and claims without changing stock or accounting automatically.'],
                'advanced_variants_enabled' => ['Advanced variants', 'Enables the variant foundation based on existing ProductStock size, color, SKU, and barcode combinations.'],
            ] as $key => [$label, $description])
                <div class="form-group">
                    <label class="aiz-switch aiz-switch-success mb-2">
                        <input type="checkbox" name="{{ $key }}" value="1" @checked($policy[$key])>
                        <span class="slider round"></span>
                    </label>
                    <strong class="ml-2">{{ translate($label) }}</strong>
                    <p class="text-muted mb-0">{{ translate($description) }}</p>
                </div>
                <hr>
            @endforeach

            <div class="form-group">
                <label class="aiz-switch aiz-switch-success mb-2">
                    <input type="checkbox" name="allow_negative_stock" value="1" @checked($policy['allow_negative_stock'])>
                    <span class="slider round"></span>
                </label>
                <strong class="ml-2">{{ translate('Allow negative stock') }}</strong>
                <p class="text-muted mb-0">{{ translate('When disabled, sales, purchase returns, and adjustments cannot reduce stock below zero.') }}</p>
            </div>

            <button class="btn btn-primary" type="submit">{{ translate('Save policy') }}</button>
        </form>
    </div>
</div>
@endsection
