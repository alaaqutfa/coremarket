@extends('seller.layouts.app')

@section('panel_content')

    <div class="aiz-titlebar mt-2 mb-4">
      <div class="row align-items-center">
        <div class="col-md-6">
            <h1 class="h3">{{ translate('Requests Manegment') }}</h1>
        </div>
      </div>
    </div>

    <div class="row gutters-10 justify-content-center">
        @if (addon_is_activated('seller_subscription'))
            <div class="col-md-4 mx-auto mb-3" >
                <div class="bg-grad-1 text-white rounded-lg overflow-hidden">
                  <span class="size-30px rounded-circle mx-auto bg-soft-primary d-flex align-items-center justify-content-center mt-3">
                      <i class="las la-upload la-2x text-white"></i>
                  </span>
                  <div class="px-3 pt-3 pb-3">
                      <div class="h4 fw-700 text-center">{{ max(0, auth()->user()->shop->product_upload_limit - auth()->user()->products()->count()) }}</div>
                      <div class="opacity-50 text-center">{{  translate('Remaining Uploads') }}</div>
                  </div>
                </div>
            </div>
        @endif
        @if (addon_is_activated('seller_subscription'))
        @php
            $seller_package = \App\Models\SellerPackage::find(Auth::user()->shop->seller_package_id);
        @endphp
        <div class="col-md-4">
            <a href="{{ route('seller.seller_packages_list') }}" class="text-center bg-white shadow-sm hov-shadow-lg text-center d-block p-3 rounded">
                @if($seller_package != null)
                    <img src="{{ uploaded_asset($seller_package->logo) }}" height="44" class="mw-100 mx-auto">
                    <span class="d-block sub-title mb-2">{{ translate('Current Package')}}: {{ $seller_package->getTranslation('name') }}</span>
                @else
                    <i class="la la-frown-o mb-2 la-3x"></i>
                    <div class="d-block sub-title mb-2">{{ translate('No Package Found')}}</div>
                @endif
                <div class="btn btn-outline-primary py-1">{{ translate('Upgrade Package')}}</div>
            </a>
        </div>
        @endif

    </div>

    <div class="card">
        <form class="" action="" method="DELETE">
            @csrf
            @method('DELETE')
            <div class="card-header row gutters-5">
                <div class="col">
                    <h5 class="mb-md-0 h6">{{ translate('All Requests') }}</h5>
                </div>
            </div>
            <div class="card-body">
                <table class="table aiz-table mb-0">
                    <thead>
                        <tr>
                            <th>
                                <div class="form-group">
                                    <div class="aiz-checkbox-inline">
                                        <label class="aiz-checkbox">
                                            <input type="checkbox" class="check-all">
                                            <span class="aiz-square-check"></span>
                                        </label>
                                    </div>
                                </div>
                            </th>
                            <th width="30%">{{ translate('Name')}}</th>
                            <th >{{ translate('Seller Name')}}</th>
                            <th data-breakpoints="md">{{ translate('Kind')}}</th>
                            <th data-breakpoints="md">{{ translate('Approval')}}</th>
                            <th data-breakpoints="md" class="text-right">{{ translate('Options')}}</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($request as $key => $request)
                            <tr>
                                <td>
                                    <div class="form-group d-inline-block">
                                        <label class="aiz-checkbox">
                                            <input type="checkbox" class="check-one" name="id[]" value="{{$request->id}}">
                                            <span class="aiz-square-check"></span>
                                        </label>
                                    </div>
                                </td>
                                <td>
                                    {{ $request->name }}
                                </td>
                                <td>{{ $request->seller->name ?? 'No Seller' }}</td>
                                <td>
                                    {{ translate($request->kind) }}
                                </td>
                                <td>
                                    <label class="aiz-switch aiz-switch-success mb-0">
                                        <input onchange="update_approved(this)" value="{{ $request->id }}" type="checkbox" <?php if($request->approved == 1) echo "checked";?> >
                                        <span class="slider round"></span>
                                    </label>
                                </td>
                                <td class="text-right">
                                <a href="#" class="btn btn-soft-danger btn-icon btn-circle btn-sm confirm-delete" data-href="{{route('sellers.request.destroy', $request->id)}}" title="{{ translate('Delete') }}">
                                    <i class="las la-trash"></i>
                                </a>
                                </td>
                            </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                
            </div>
        </form>
    </div>

@endsection

@section('modal')
    <!-- Delete modal -->
    @include('modals.delete_modal')
    <!-- Bulk Delete modal -->
    @include('modals.bulk_delete_modal')
@endsection

@section('script')
<script type="text/javascript">
    function update_approved(el) {
        var status = el.checked ? 1 : 0; 
        
        $.ajax({
            url: '{{ route('seller.requests.approved') }}',
            type: 'POST',
            data: {
                _token: '{{ csrf_token() }}', 
                id: el.value,
                status: status
            },
            success: function(data) {
                if (data == 1) {
                    AIZ.plugins.notify('success', '{{ translate('Approved request updated successfully') }}');
                } else if (data == 2) {
                    AIZ.plugins.notify('danger', '{{ translate('Please upgrade your package.') }}');
                    location.reload();
                } else {
                    AIZ.plugins.notify('danger', '{{ translate('Something went wrong') }}');
                    location.reload();
                }
            },
            error: function(xhr, status, error) {
                AIZ.plugins.notify('danger', '{{ translate('Something went wrong') }}');
                location.reload();
            }
        });
    }
</script>
@endsection
