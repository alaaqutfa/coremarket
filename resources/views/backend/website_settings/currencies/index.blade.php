@extends('backend.layouts.app')

@section('content')
    <div class="card">
        <div class="card-header row gutters-5">
            <div class="col text-center text-md-left">
                <h5 class="mb-md-0 h6">{{ translate('Currency Rates') }}</h5>
                <small class="text-muted">{{ translate('Update exchange rates for currencies already enabled by the platform owner.') }}</small>
            </div>
            <div class="col-md-4">
                <form id="sort_currencies" action="" method="GET">
                    <div class="input-group input-group-sm">
                        <input type="text" class="form-control" id="search" name="search" @isset($sort_search) value="{{ $sort_search }}" @endisset placeholder="{{ translate('Type currency name or code & Enter') }}">
                    </div>
                </form>
            </div>
        </div>
        <div class="card-body">
            <table class="table aiz-table mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>{{ translate('Currency name') }}</th>
                        <th>{{ translate('Symbol') }}</th>
                        <th>{{ translate('Code') }}</th>
                        <th>{{ translate('Exchange rate') }} (1 USD = ?)</th>
                        <th class="text-right">{{ translate('Save') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($currencies as $key => $currency)
                        <tr>
                            <td>{{ ($key + 1) + ($currencies->currentPage() - 1) * $currencies->perPage() }}</td>
                            <td>{{ $currency->name }}</td>
                            <td>{{ $currency->symbol }}</td>
                            <td>{{ $currency->code }}</td>
                            <td style="min-width: 180px;">
                                <form action="{{ route('website.currency-rates.update') }}" method="POST" class="d-flex align-items-center justify-content-end gap-2">
                                    @csrf
                                    <input type="hidden" name="id" value="{{ $currency->id }}">
                                    <input
                                        type="number"
                                        name="exchange_rate"
                                        step="0.000001"
                                        min="0.000001"
                                        value="{{ $currency->exchange_rate }}"
                                        class="form-control"
                                        style="max-width: 160px;"
                                        required
                                    >
                            </td>
                            <td class="text-right">
                                    <button type="submit" class="btn btn-sm btn-primary">{{ translate('Save') }}</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted">{{ translate('No enabled currencies found.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <div class="aiz-pagination">
                {{ $currencies->appends(request()->input())->links() }}
            </div>
        </div>
    </div>
@endsection
