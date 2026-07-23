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

            <div class="form-group">
                <label class="aiz-switch aiz-switch-success mb-2">
                    <input type="checkbox" name="strict_inventory_mode" value="1" @checked($policy['strict_inventory_mode'])>
                    <span class="slider round"></span>
                </label>
                <strong class="ml-2">{{ translate('Strict inventory mode') }}</strong>
                <p class="text-muted mb-0">{{ translate('Stock entries should come from purchase receipts or authorized adjustments. Product edits preserve existing quantities.') }}</p>
            </div>

            <hr>

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
