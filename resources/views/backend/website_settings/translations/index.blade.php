@extends('backend.layouts.app')

@section('content')
    <div class="card">
        <div class="card-header row gutters-5">
            <div class="col text-center text-md-left">
                <h5 class="mb-md-0 h6">{{ translate('Translations') }}</h5>
                <small class="text-muted">{{ translate('Edit only the languages already enabled for this store.') }}</small>
            </div>
            <div class="col-md-4">
                <form id="sort_languages" action="" method="GET">
                    <div class="input-group input-group-sm">
                        <input type="text" class="form-control" id="search" name="search" @isset($sort_search) value="{{ $sort_search }}" @endisset placeholder="{{ translate('Type language name & Enter') }}">
                    </div>
                </form>
            </div>
        </div>
        <div class="card-body">
            <table class="table aiz-table mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>{{ translate('Language') }}</th>
                        <th>{{ translate('Code') }}</th>
                        <th>{{ translate('App Lang Code') }}</th>
                        <th class="text-right">{{ translate('Options') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($languages as $key => $language)
                        <tr>
                            <td>{{ ($key + 1) + ($languages->currentPage() - 1) * $languages->perPage() }}</td>
                            <td>{{ $language->name }}</td>
                            <td>{{ $language->code }}</td>
                            <td>{{ $language->app_lang_code }}</td>
                            <td class="text-right">
                                <a class="btn btn-soft-info btn-icon btn-circle btn-sm" href="{{ route('website.translations.show', $language->id) }}" title="{{ translate('Edit translations') }}">
                                    <i class="las la-language"></i>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted">{{ translate('No enabled languages found.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <div class="aiz-pagination">
                {{ $languages->appends(request()->input())->links() }}
            </div>
        </div>
    </div>
@endsection
