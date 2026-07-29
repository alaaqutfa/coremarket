@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3"><h1 class="h3">{{ translate('Branches & Staff Policies') }}</h1></div>
<div class="row">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header"><h5 class="mb-0 h6">{{ translate('Foundation Settings') }}</h5></div>
            <div class="card-body">
                <form action="{{ route('operations.branches.settings') }}" method="POST">
                    @csrf
                    @method('PUT')
                    @foreach([
                        'branches_enabled' => ['Branches enabled', $settings['enabled']],
                        'price_lists_enabled' => ['Price Lists enabled', $settings['price_lists_enabled']],
                        'flexible_selling_price_enabled' => ['Flexible selling price enabled', $settings['flexible_selling_price_enabled']],
                        'branch_pricing_enabled' => ['Branch pricing enabled', $settings['branch_pricing_enabled']],
                    ] as $name => [$label, $checked])
                        <label class="aiz-switch aiz-switch-success d-block mb-3">
                            <input type="checkbox" name="{{ $name }}" value="1" @checked($checked)>
                            <span class="slider round"></span><span class="ml-2">{{ translate($label) }}</span>
                        </label>
                    @endforeach
                    <div class="form-group">
                        <label>{{ translate('Price Policy') }}</label>
                        <select name="price_policy" class="form-control">
                            <option value="unified" @selected($settings['price_policy'] === 'unified')>{{ translate('Unified') }}</option>
                            <option value="branch_specific" @selected(in_array($settings['price_policy'], ['branch_specific', 'branch_specific_future'], true))>{{ translate('Branch-specific') }}</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>{{ translate('Branch Pricing Priority') }}</label>
                        <select name="branch_pricing_priority" class="form-control">
                            @foreach(\App\Services\CoreMarketBranchPricingService::PRIORITIES as $priority)
                                <option value="{{ $priority }}" @selected($settings['branch_pricing_priority'] === $priority)>{{ translate(ucwords(str_replace('_', ' ', $priority))) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label>{{ translate('Inventory Policy') }}</label>
                        <select name="inventory_policy" class="form-control">
                            <option value="unified" @selected($settings['inventory_policy'] === 'unified')>{{ translate('Unified') }}</option>
                            <option value="branch_specific_future" @selected($settings['inventory_policy'] === 'branch_specific_future')>{{ translate('Branch-specific (future)') }}</option>
                        </select>
                    </div>
                    <p class="small text-muted">{{ translate('Branch pricing uses an active branch price when configured and safely falls back to customer, sale, or public pricing. Inventory policy is managed separately.') }}</p>
                    <button class="btn btn-primary btn-sm">{{ translate('Save Policies') }}</button>
                </form>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h5 class="mb-0 h6">{{ translate('Add Branch') }}</h5></div>
            <div class="card-body">
                <form action="{{ route('operations.branches.store') }}" method="POST">
                    @csrf
                    @include('backend.operations.branches.fields', ['branch' => new \App\Models\StoreBranch(['is_active' => true])])
                    <button class="btn btn-primary btn-sm">{{ translate('Add Branch') }}</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        @foreach($branches as $branch)
            <div class="card">
                <div class="card-header"><h5 class="mb-0 h6">{{ $branch->name }} @if($branch->is_default)<span class="badge badge-info">{{ translate('Default') }}</span>@endif</h5></div>
                <div class="card-body">
                    <form action="{{ route('operations.branches.update', $branch) }}" method="POST">
                        @csrf
                        @method('PUT')
                        @include('backend.operations.branches.fields', compact('branch'))
                        <button class="btn btn-soft-primary btn-sm">{{ translate('Update Branch') }}</button>
                    </form>
                </div>
            </div>
        @endforeach
    </div>
</div>
@endsection
