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
                            <h1 class="h3 mb-2">{{ translate('My Subscription') }}</h1>
                            <p class="text-muted mb-0">
                                {{ translate('Managed runtime overview for your current store access and plan usage.') }}
                            </p>
                        </div>
                        <span class="badge badge-{{ $statusVariant }} badge-inline px-3 py-2 mt-2 mt-md-0">
                            {{ translate('License status') }}: {{ translate($statusText) }}
                        </span>
                    </div>

                    <div class="alert alert-info mt-4 mb-0">
                        <strong>{{ translate('Managed by CorePilotOS') }}:</strong>
                        {{ translate($subscriptionStatusNote) }}
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
                        <span class="text-muted fs-14">/ {{ $licenseSnapshot['limits']['products_limit'] ?? translate('Unlimited') }}</span>
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
                        <span class="text-muted fs-14">/ {{ $licenseSnapshot['limits']['monthly_orders_limit'] ?? translate('Unlimited') }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-3">
        <div class="col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-header">
                    <h5 class="mb-0 h6">{{ translate('Subscription status') }}</h5>
                </div>
                <div class="card-body">
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item px-0 d-flex justify-content-between">
                            <span class="text-muted">{{ translate('Store name') }}</span>
                            <span>{{ $storeInfo['store_name'] }}</span>
                        </li>
                        <li class="list-group-item px-0 d-flex justify-content-between">
                            <span class="text-muted">{{ translate('Domain') }}</span>
                            <span>{{ $storeInfo['domain'] ?: translate('Not set') }}</span>
                        </li>
                        <li class="list-group-item px-0 d-flex justify-content-between">
                            <span class="text-muted">{{ translate('Status') }}</span>
                            <span>{{ translate($statusText) }}</span>
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
                            <span class="text-muted d-block">{{ translate('Support note') }}</span>
                            <span>{{ translate('Contact support to upgrade or activate features.') }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-lg-4 mt-3 mt-lg-0">
            <div class="card shadow-sm h-100">
                <div class="card-header">
                    <h5 class="mb-0 h6">{{ translate('Enabled features') }}</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        @foreach ($enabledFeatureRows as $feature)
                            <div class="col-12 mb-2">
                                <div class="d-flex justify-content-between border rounded px-3 py-2">
                                    <span>{{ translate($feature['label']) }}</span>
                                    <span class="badge badge-success">{{ translate('Enabled') }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4 mt-3 mt-lg-0">
            <div class="card shadow-sm h-100">
                <div class="card-header">
                    <h5 class="mb-0 h6">{{ translate('Disabled features') }}</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        @foreach ($disabledFeatureRows as $feature)
                            <div class="col-12 mb-2">
                                <div class="d-flex justify-content-between border rounded px-3 py-2">
                                    <span>{{ translate($feature['label']) }}</span>
                                    <span class="badge badge-secondary">{{ translate('Disabled') }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-3">
        <div class="col-lg-7">
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
                                                @if ($limit['usage_note'])
                                                    <div class="text-muted fs-12">{{ translate($limit['usage_note']) }}</div>
                                                @endif
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

        <div class="col-lg-5 mt-3 mt-lg-0">
            <div class="card shadow-sm h-100">
                <div class="card-header">
                    <h5 class="mb-0 h6">{{ translate('Usage snapshot') }}</h5>
                </div>
                <div class="card-body">
                    <div class="border rounded px-3 py-2 mb-2">
                        <div class="text-muted fs-12">{{ translate('Products count') }}</div>
                        <div class="h5 mb-0">{{ $currentProductCount }}</div>
                    </div>
                    <div class="border rounded px-3 py-2 mb-2">
                        <div class="text-muted fs-12">{{ translate('Monthly orders count') }}</div>
                        <div class="h5 mb-0">{{ $currentMonthlyOrderCount }}</div>
                    </div>
                    <div class="border rounded px-3 py-2 mb-2">
                        <div class="text-muted fs-12">{{ translate('Uploads count') }}</div>
                        <div class="h5 mb-0">{{ $currentUploadCount }}</div>
                    </div>
                    <div class="alert alert-warning mb-0 mt-3">
                        {{ translate('Storage usage is shown as a safe uploads placeholder until a reliable storage meter is added.') }}
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
