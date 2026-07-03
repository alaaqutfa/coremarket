@php
    $statusClass = 'info';
    $statusLabel = ucfirst(str_replace('_', ' ', $coremarket_license_status_card['status']));

    if ($coremarket_license_status_card['needs_attention'] || $coremarket_license_status_card['products_limit_reached'] || $coremarket_license_status_card['monthly_orders_limit_reached']) {
        $statusClass = 'danger';
    } elseif ($coremarket_license_status_card['products_near_limit'] || $coremarket_license_status_card['monthly_orders_near_limit'] || $coremarket_license_status_card['in_grace_period']) {
        $statusClass = 'warning';
    } elseif ($coremarket_license_status_card['status'] === 'active') {
        $statusClass = 'success';
    }
@endphp

<div class="mb-3">
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="d-flex flex-wrap align-items-start justify-content-between mb-3">
                <div>
                    <h5 class="mb-1">{{ translate('Plan Status') }}</h5>
                    <p class="text-muted fs-12 mb-0">{{ translate('Current subscription and plan usage overview.') }}</p>
                </div>
                <span class="badge badge-{{ $statusClass }} badge-inline">{{ translate($statusLabel) }}</span>
            </div>

            <div class="row gutters-10">
                <div class="col-md-3 col-sm-6">
                    <div class="border rounded p-3 h-100">
                        <div class="text-secondary fs-12 mb-1">{{ translate('Plan') }}</div>
                        <div class="fw-700 text-dark">{{ ucwords(str_replace('_', ' ', $coremarket_license_status_card['plan_code'])) }}</div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="border rounded p-3 h-100">
                        <div class="text-secondary fs-12 mb-1">{{ translate('Subscription') }}</div>
                        <div class="fw-700 text-dark">{{ translate($statusLabel) }}</div>
                        @if ($coremarket_license_status_card['grace_until'])
                            <div class="text-muted fs-12 mt-1">
                                {{ translate('Grace until') }}: {{ $coremarket_license_status_card['grace_until'] }}
                            </div>
                        @elseif ($coremarket_license_status_card['expires_at'])
                            <div class="text-muted fs-12 mt-1">
                                {{ translate('Expires on') }}: {{ $coremarket_license_status_card['expires_at'] }}
                            </div>
                        @endif
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="border rounded p-3 h-100">
                        <div class="text-secondary fs-12 mb-1">{{ translate('Products') }}</div>
                        <div class="fw-700 text-dark">
                            {{ $coremarket_license_status_card['products_count'] }} / {{ $coremarket_license_status_card['products_limit'] }}
                        </div>
                        <div class="progress mt-2" style="height: 6px;">
                            <div class="progress-bar bg-{{ $coremarket_license_status_card['products_limit_reached'] ? 'danger' : ($coremarket_license_status_card['products_near_limit'] ? 'warning' : 'success') }}"
                                role="progressbar"
                                style="width: {{ $coremarket_license_status_card['products_usage_percentage'] }}%;"
                                aria-valuenow="{{ $coremarket_license_status_card['products_usage_percentage'] }}"
                                aria-valuemin="0"
                                aria-valuemax="100"></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="border rounded p-3 h-100">
                        <div class="text-secondary fs-12 mb-1">{{ translate('Orders this month') }}</div>
                        <div class="fw-700 text-dark">
                            {{ $coremarket_license_status_card['monthly_orders_count'] }} / {{ $coremarket_license_status_card['monthly_orders_limit'] }}
                        </div>
                        <div class="progress mt-2" style="height: 6px;">
                            <div class="progress-bar bg-{{ $coremarket_license_status_card['monthly_orders_limit_reached'] ? 'danger' : ($coremarket_license_status_card['monthly_orders_near_limit'] ? 'warning' : 'success') }}"
                                role="progressbar"
                                style="width: {{ $coremarket_license_status_card['monthly_orders_usage_percentage'] }}%;"
                                aria-valuenow="{{ $coremarket_license_status_card['monthly_orders_usage_percentage'] }}"
                                aria-valuemin="0"
                                aria-valuemax="100"></div>
                        </div>
                    </div>
                </div>
            </div>

            @if ($coremarket_license_status_card['needs_attention'])
                <div class="alert alert-danger mb-0 mt-3">
                    {{ translate('Subscription status requires attention. Please contact support.') }}
                </div>
            @elseif ($coremarket_license_status_card['products_limit_reached'] || $coremarket_license_status_card['monthly_orders_limit_reached'])
                <div class="alert alert-danger mb-0 mt-3">
                    {{ translate('Plan limit reached. Please contact support.') }}
                </div>
            @elseif ($coremarket_license_status_card['products_near_limit'] || $coremarket_license_status_card['monthly_orders_near_limit'])
                <div class="alert alert-warning mb-0 mt-3">
                    {{ translate('You are close to your plan limit.') }}
                </div>
            @endif
        </div>
    </div>
</div>
