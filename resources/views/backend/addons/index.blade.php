@extends('backend.layouts.app')

@section('content')

    <div class="">
        <div class="row ">
            <div class="col-md-6">
                <div class="nav border-bottom aiz-nav-tabs">
                    <a class="p-3 fs-16 text-reset show active" data-toggle="tab" href="#installed">{{ translate('Installed Addon')}}</a>
                    <a class="p-3 fs-16 text-reset" data-toggle="tab" href="#available">{{ translate('Available Addon')}}</a>
                </div>
            </div>
            <div class="col mt-3 mt-md-0 text-center text-md-right">
                <span class="d-inline-block text-muted fs-13 mb-3 mb-sm-0 mx-3 mx-md-0 mr-lg-3">
                    {{ translate('External addon marketplace links are disabled in this managed baseline.') }}
                </span>
                <a href="{{ route('addons.create')}}" class="btn btn-primary mx-3 mx-md-0">{{ translate('Install/Update Addon')}}</a>
            </div>
        </div>
    </div>
    <br>
    <div class="tab-content">
        <div class="tab-pane fade in active show" id="installed">
            <div class="row">
                <div class="col-xl-10 col-xxl-8 mx-auto">
                    <div class="card">
                        <div class="card-body">
                            <ul class="list-group">
                                @forelse($addons as $key => $addon)
                                    <li class="list-group-item">
                                        <div class="align-items-center d-flex flex-column flex-md-row">
                                            <img class="h-60px mb-3 mb-md-0" src="{{ static_asset($addon->image) }}" alt="Image">
                                            <div class="mr-md-3 ml-md-5">
                                                <h4 class="fs-16 fw-600">{{ ucfirst($addon->name) }}</h4>
                                            </div>
                                            <div class="mr-md-3 ml-0">
                                                <p><small>{{ translate('Version')}}: </small>{{ $addon->version }}</p>
                                            </div>
                                            @if (env('DEMO_MODE') != 'On')
                                                <div class="mr-md-3 ml-0 w-100 w-md-auto">
                                                    <p><small>{{ translate('Purchase code')}}: </small>{{ $addon->purchase_code }}</p>
                                                </div>
                                            @endif
                                            <div class="ml-auto mr-0">
                                                <label class="aiz-switch mb-0">
                                                    <input type="checkbox" onchange="updateStatus(this, {{ $addon->id }})" <?php if($addon->activated) echo "checked";?>>
                                                    <span></span>
                                                </label>
                                            </div>
                                        </div>
                                    </li>
                                @empty
                                    <li class="list-group-item">
                                        <div class="text-center">
                                            <img class="mw-100 h-200px" src="{{ static_asset('assets/img/nothing.svg') }}" alt="Image">
                                            <h5 class="mb-0 h5 mt-3">{{ translate('No Addon Installed')}}</h5>
                                        </div>
                                    </li>
                                @endforelse
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="tab-pane fade" id="available">
            <div class="row" id="available-addons-content">

            </div>
        </div>
    </div>




@endsection

@section('script')
    <script type="text/javascript">
        function updateStatus(el, id){

            if('{{env('DEMO_MODE')}}' == 'On'){
                AIZ.plugins.notify('info', '{{ translate('Data can not change in demo mode.') }}');
                return;
            }


            if($(el).is(':checked')){
                var status = 1;
            }
            else{
                var status = 0;
            }
            $.post('{{ route('addons.activation') }}', {_token:'{{ csrf_token() }}', id:id, status:status}, function(data){
                if(data == 1){
                    AIZ.plugins.notify('success', '{{ translate('Status updated successfully') }}');
                }
                else{
                    AIZ.plugins.notify('danger', '{{ translate('Something went wrong') }}');
                }
            });
        }

        $(document).ready(function(){
            $('#available-addons-content').html(
                `<div class="col-12">
                    <div class="card addon-card">
                        <div class="card-body text-center py-5">
                            <h4 class="fs-16 fw-600 mb-2">{{ translate('External addon listings are disabled') }}</h4>
                            <p class="mb-0 text-muted">{{ translate('Use approved local packages or managed release files instead of legacy vendor feeds.') }}</p>
                        </div>
                    </div>
                </div>`
            );
        })
    </script>
@endsection
