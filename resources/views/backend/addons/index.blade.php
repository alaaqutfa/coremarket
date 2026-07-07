@extends('backend.layouts.app')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex flex-wrap align-items-start justify-content-between">
                        <div class="pr-3">
                            <h1 class="h3 mb-2">{{ translate('Addon Requests') }}</h1>
                            <p class="text-muted mb-0">
                                {{ translate('Request optional managed capabilities without installing or activating code from the admin panel.') }}
                            </p>
                        </div>
                        <a href="{{ route('subscription.index') }}" class="btn btn-soft-primary mt-2 mt-md-0">
                            {{ translate('View My Subscription') }}
                        </a>
                    </div>

                    <div class="alert alert-info mt-4 mb-0">
                        <strong>{{ translate('Managed baseline') }}:</strong>
                        {{ translate('Add-ons are request-only in CoreMarket. Installation, uploads, activation toggles, and external vendor callbacks are intentionally disabled.') }}
                    </div>

                    @if (! $isStoreAdminViewer)
                        <div class="alert alert-warning mt-3 mb-0">
                            {{ translate('Owner/admin note: legacy add-on upload, code install, SQL execution, and activation toggles are hidden and neutralized in this managed baseline.') }}
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-3">
        @foreach ($addonCatalog as $addon)
            <div class="col-lg-6 col-xl-4 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <h5 class="mb-0 h6">{{ translate($addon['title']) }}</h5>
                            <span class="badge badge-{{ $addon['enabled'] ? 'success' : 'secondary' }}">
                                {{ translate($addon['status_label']) }}
                            </span>
                        </div>

                        <p class="text-muted fs-13 flex-grow-1 mb-3">{{ translate($addon['description']) }}</p>

                        <div class="mb-3">
                            <span class="badge badge-{{ $addon['available_in_plan'] ? 'info' : 'warning' }}">
                                {{ translate($addon['plan_label']) }}
                            </span>
                        </div>

                        @if ($requestUrl)
                            <a href="{{ $requestUrl }}" class="btn btn-soft-primary btn-block" target="_blank" rel="noopener">
                                {{ translate('Request Activation via') }} {{ translate($requestChannel) }}
                            </a>
                        @else
                            <button type="button" class="btn btn-soft-secondary btn-block" disabled>
                                {{ translate('Contact support to request activation') }}
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endsection
