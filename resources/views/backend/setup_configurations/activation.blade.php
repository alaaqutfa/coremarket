@extends('backend.layouts.app')

@php
    $statusVariant = 'success';
    $statusText = 'Active';

    if ($isLicenseSuspended) {
        $statusVariant = 'danger';
        $statusText = 'Suspended';
    } elseif ($isLicenseExpired && ! $isInGracePeriod) {
        $statusVariant = 'danger';
        $statusText = 'Expired';
    } elseif ($isInGracePeriod) {
        $statusVariant = 'warning';
        $statusText = 'Grace period';
    }
@endphp

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex flex-wrap align-items-start justify-content-between">
                        <div class="pr-3">
                            <h1 class="h3 mb-2">{{ translate('Activation control center') }}</h1>
                            <p class="text-muted mb-0">
                                {{ translate('Internal owner/admin support overview for CoreMarket runtime access.') }}
                            </p>
                        </div>
                        <span class="badge badge-{{ $statusVariant }} badge-inline px-3 py-2 mt-2 mt-md-0">
                            {{ translate('License status') }}: {{ translate($statusText) }}
                        </span>
                    </div>

                    <div class="alert alert-info mt-4 mb-0">
                        <strong>{{ translate('CorePilotOS source of truth') }}:</strong>
                        {{ translate('Commercial plans, pricing, subscriptions, renewals, and activation decisions stay in CorePilotOS. CoreMarket only enforces the applied runtime access snapshot shown here.') }}
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-3">
        <div class="col-lg-3 col-sm-6">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted fs-12 text-uppercase">{{ translate('Applied plan') }}</div>
                    <div class="h4 mb-0">{{ strtoupper((string) ($featureMatrix['applied_plan'] ?? 'starter')) }}</div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-sm-6">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted fs-12 text-uppercase">{{ translate('Store mode') }}</div>
                    <div class="h4 mb-0">{{ str_replace('_', ' ', (string) ($featureMatrix['store_mode'] ?? 'single_store')) }}</div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-sm-6 mt-3 mt-lg-0">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted fs-12 text-uppercase">{{ translate('Products usage') }}</div>
                    <div class="h4 mb-0">
                        {{ $currentProductCount }}
                        <span class="text-muted fs-14">/ {{ $licenseSnapshot['limits']['products_limit'] ?? '-' }}</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-sm-6 mt-3 mt-lg-0">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted fs-12 text-uppercase">{{ translate('Monthly orders') }}</div>
                    <div class="h4 mb-0">
                        {{ $currentMonthlyOrderCount }}
                        <span class="text-muted fs-14">/ {{ $licenseSnapshot['limits']['monthly_orders_limit'] ?? '-' }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-3">
        <div class="col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-header">
                    <h5 class="mb-0 h6">{{ translate('License snapshot') }}</h5>
                </div>
                <div class="card-body">
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item px-0 d-flex justify-content-between">
                            <span class="text-muted">{{ translate('License enabled') }}</span>
                            <span>{{ $licenseSnapshot['license_enabled'] ? translate('Yes') : translate('No') }}</span>
                        </li>
                        <li class="list-group-item px-0 d-flex justify-content-between">
                            <span class="text-muted">{{ translate('Instance ID') }}</span>
                            <span>{{ $storeInfo['instance_id'] ?: translate('Not set') }}</span>
                        </li>
                        <li class="list-group-item px-0 d-flex justify-content-between">
                            <span class="text-muted">{{ translate('Domain') }}</span>
                            <span>{{ $storeInfo['domain'] ?: translate('Not set') }}</span>
                        </li>
                        <li class="list-group-item px-0 d-flex justify-content-between">
                            <span class="text-muted">{{ translate('Starts at') }}</span>
                            <span>{{ $licenseSnapshot['starts_at'] ?: translate('Not set') }}</span>
                        </li>
                        <li class="list-group-item px-0 d-flex justify-content-between">
                            <span class="text-muted">{{ translate('Expires at') }}</span>
                            <span>{{ $licenseSnapshot['expires_at'] ?: translate('Not set') }}</span>
                        </li>
                        <li class="list-group-item px-0 d-flex justify-content-between">
                            <span class="text-muted">{{ translate('Grace until') }}</span>
                            <span>{{ $licenseSnapshot['grace_until'] ?: translate('Not set') }}</span>
                        </li>
                        <li class="list-group-item px-0">
                            <span class="text-muted d-block">{{ translate('Suspension reason') }}</span>
                            <span>{{ $licenseSnapshot['suspension_reason'] ?: translate('None') }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-lg-4 mt-3 mt-lg-0">
            <div class="card shadow-sm h-100">
                <div class="card-header">
                    <h5 class="mb-0 h6">{{ translate('Store and support info') }}</h5>
                </div>
                <div class="card-body">
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item px-0 d-flex justify-content-between">
                            <span class="text-muted">{{ translate('Store name') }}</span>
                            <span>{{ $storeInfo['store_name'] }}</span>
                        </li>
                        <li class="list-group-item px-0 d-flex justify-content-between">
                            <span class="text-muted">{{ translate('App URL') }}</span>
                            <span>{{ $storeInfo['app_url'] ?: translate('Not set') }}</span>
                        </li>
                        <li class="list-group-item px-0 d-flex justify-content-between">
                            <span class="text-muted">{{ translate('Support email') }}</span>
                            <span>{{ $storeInfo['support_email'] ?: translate('Not set') }}</span>
                        </li>
                        <li class="list-group-item px-0 d-flex justify-content-between">
                            <span class="text-muted">{{ translate('Contact phone') }}</span>
                            <span>{{ $storeInfo['contact_phone'] ?: translate('Not set') }}</span>
                        </li>
                        <li class="list-group-item px-0 d-flex justify-content-between">
                            <span class="text-muted">{{ translate('Owner support email') }}</span>
                            <span>{{ $storeInfo['support_owner_email'] ?: translate('Not set') }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-lg-4 mt-3 mt-lg-0">
            <div class="card shadow-sm h-100">
                <div class="card-header">
                    <h5 class="mb-0 h6">{{ translate('Setup checklist status') }}</h5>
                </div>
                <div class="card-body">
                    @foreach ($setupChecklist as $item)
                        @php
                            $itemVariant = $item['state'] === 'ok' ? 'success' : ($item['state'] === 'attention' ? 'danger' : 'warning');
                            $itemText = $item['state'] === 'ok' ? 'PASS' : ($item['state'] === 'attention' ? 'FAIL' : 'WARN');
                        @endphp
                        <div class="border rounded px-3 py-2 mb-2">
                            <div class="d-flex justify-content-between align-items-center">
                                <strong>{{ translate($item['label']) }}</strong>
                                <span class="badge badge-{{ $itemVariant }}">{{ $itemText }}</span>
                            </div>
                            <div class="text-muted fs-13 mt-1">{{ translate($item['summary']) }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-3">
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header">
                    <h5 class="mb-0 h6">{{ translate('Enabled runtime features') }}</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        @foreach ($featureRows as $feature)
                            <div class="col-sm-6 mb-2">
                                <div class="d-flex justify-content-between border rounded px-3 py-2">
                                    <span>{{ translate($feature['label']) }}</span>
                                    <span class="badge badge-{{ $feature['enabled'] ? 'success' : 'secondary' }}">
                                        {{ $feature['enabled'] ? translate('Enabled') : translate('Disabled') }}
                                    </span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6 mt-3 mt-lg-0">
            <div class="card shadow-sm h-100">
                <div class="card-header">
                    <h5 class="mb-0 h6">{{ translate('Runtime limits') }}</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead>
                                <tr>
                                    <th>{{ translate('Limit') }}</th>
                                    <th>{{ translate('Configured value') }}</th>
                                    <th>{{ translate('Current usage') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($limitRows as $limit)
                                    <tr>
                                        <td>{{ translate($limit['label']) }}</td>
                                        <td>{{ $limit['value'] ?? translate('Unlimited') }}</td>
                                        <td>
                                            @if ($limit['usage'] === null)
                                                <span class="text-muted">{{ translate('Not tracked here') }}</span>
                                            @else
                                                {{ $limit['usage'] }}
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-3">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header">
                    <h5 class="mb-0 h6">{{ translate('Legacy activation controls') }}</h5>
                </div>
                <div class="card-body">
                    <p class="mb-2">
                        {{ translate('Unsafe legacy vendor and environment toggles are intentionally hidden from this page.') }}
                    </p>
                    <p class="text-muted mb-0">
                        {{ translate('Use managed instance setup, configuration review, and CorePilotOS-applied runtime inputs to make controlled changes instead of direct activation toggles.') }}
                    </p>
                </div>
            </div>
        </div>
    </div>
@endsection
