@extends('backend.layouts.app')

@section('content')
    <div class="aiz-titlebar text-left mt-2 mb-3">
        <div class="row align-items-center">
            <div class="col-md-6">
                <h1 class="h3">{{ translate('All Taxes') }}</h1>
            </div>
            <div class="col-md-6 text-md-right">
                <a href="#" data-target="#add-tax" data-toggle="modal" class="btn btn-circle btn-info">
                    <span>{{ translate('Add New Tax') }}</span>
                </a>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header row gutters-5">
            <div class="col text-center text-md-left">
                <h5 class="mb-md-0 h6">{{ translate('All Taxes') }}</h5>
            </div>
        </div>
        <div class="card-body">
            <table class="table aiz-table mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>{{ translate('Tax Name') }}</th>
                        <th>{{ translate('Type') }}</th>
                        <th>{{ translate('Category Type') }}</th>
                        <th>{{ translate('Tax Type') }}</th>
                        <th>{{ translate('Value') }}</th>
                        <th>{{ translate('Status') }}</th>
                        <th class="text-right">{{ translate('Options') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($all_taxes as $key => $tax)
                        <tr>
                            <td>{{ $loop->iteration }}</td>
                            <td>{{ $tax->name }}</td>

                            <td>{{ translate($tax->type) }}</td>

                            <td>
                                @foreach ($allcategories as $category)
                                    @if ($category->id == $tax->tax_category)
                                        {{ $category->name }}
                                    @endif
                                @endforeach
                            </td>

                            <td>
                                @if ($tax->tax_type == 'amount')
                                    {{ translate('Flat') }}
                                @else
                                    {{ translate('Percent') }}
                                @endif
                            </td>

                            <td>{{ $tax->tax_value }}</td>

                            <td>
                                <label class="aiz-switch aiz-switch-success mb-0">
                                    <input onchange="update_tax_status(this)" value="{{ $tax->id }}" type="checkbox"
                                        <?php if ($tax->tax_status == 1) {
                                            echo 'checked';
                                        } ?>>
                                    <span class="slider round"></span>
                                </label>
                            </td>

                            <td class="text-right">
                                <a class="btn btn-soft-success btn-icon btn-circle btn-sm"
                                    href="{{ route('tax.edit', $tax->id) }}" title="{{ translate('Apply') }}">
                                    <i class="las la-check"></i>
                                </a>
                                <a class="btn btn-soft-primary btn-icon btn-circle btn-sm"
                                    href="{{ route('tax.edit', $tax->id) }}" title="{{ translate('Edit') }}">
                                    <i class="las la-edit"></i>
                                </a>
                                <a href="#" class="btn btn-soft-danger btn-icon btn-circle btn-sm confirm-delete"
                                    data-href="{{ route('tax.destroy', $tax->id) }}" title="{{ translate('Delete') }}">
                                    <i class="las la-trash"></i>
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

        </div>
    </div>
@endsection

@section('modal')
    <!-- Tax Add Modal -->
    <div id="add-tax" class="modal fade" role="dialog">
        <div class="modal-dialog modal-dialog-centered modal-dialog-zoom" role="document">
            <div class="modal-content">
                <div class="modal-header bord-btm">
                    <h4 class="modal-title h6">{{ translate('Add New Tax') }}</h4>
                    <button type="button" class="close" data-dismiss="modal"><span
                            aria-hidden="true">&times;</span></button>
                </div>

                <form class="form-horizontal" action="{{ route('tax.store') }}" method="POST">
                    @csrf
                    <div class="modal-body">

                        <div class="form-group">
                            <div class=" row">
                                <label class="col-sm-3 mb-2 control-label" for="name">
                                    {{ translate('Tax Name') }}
                                </label>
                                <div class="col-sm-9 mb-2">
                                    <input type="text" placeholder="{{ translate('Name') }}" id="name"
                                        name="name" class="form-control" required>
                                </div>
                                <label class="col-sm-3 mb-2 control-label" for="type">
                                    {{ translate('Product Type') }}
                                </label>
                                <div class="col-sm-9 mb-2">
                                    <select class="form-control aiz-selectpicker" name="type" id="type">
                                        <option value="physical" selected>{{ translate('physical') }}</option>
                                        <option value="digital">{{ translate('digital') }}</option>
                                    </select>
                                </div>
                                <label class="col-sm-3 mb-2 control-label" for="category_id">
                                    {{ translate('Category Type') }}
                                </label>
                                <div class="col-sm-9 mb-2">
                                    <select class="form-control aiz-selectpicker" id="category_id" name="category_id">
                                        @foreach ($categories as $category)
                                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                                        @endforeach
                                    </select>
                                    <select class="form-control d-none aiz-selectpicker" id="service_id" name="service_id" style="display: none;">
                                        @foreach ($services as $service)
                                            <option value="{{ $service->id }}">{{ $service->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <label class="col-sm-3 mb-2 control-label" for="name">
                                    {{ translate('Tax Type') }}
                                </label>
                                <div class="col-sm-9 mb-2">
                                    <select class="form-control aiz-selectpicker" name="tax_type">
                                        <option value="amount">{{ translate('Flat') }}</option>
                                        <option value="percent">{{ translate('Percent') }}</option>
                                    </select>
                                </div>
                                <label class="col-sm-3 mb-2 control-label" for="name">
                                    {{ translate('Value') }}
                                </label>
                                <div class="col-sm-9 mb-2">
                                    <input type="number" placeholder="{{ translate('Value') }}" id="tax_value"
                                        name="tax_value" class="form-control" required>
                                </div>
                            </div>
                        </div>

                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-styled btn-base-3" data-dismiss="modal">
                            {{ translate('Close') }}
                        </button>
                        <button type="submit" class="btn btn-primary btn-styled btn-base-1">
                            {{ translate('Save') }}
                        </button>
                    </div>
                </form>

            </div>
        </div>
    </div>

    @include('modals.delete_modal')
@endsection

@section('script')
    <script type="text/javascript">
        function sort_pickup_points(el) {
            $('#sort_pickup_points').submit();
        }

        function update_tax_status(el) {

            if ('{{ env('DEMO_MODE') }}' == 'On') {
                AIZ.plugins.notify('info', '{{ translate('Data can not change in demo mode.') }}');
                return;
            }


            if (el.checked) {
                var status = 1;
            } else {
                var status = 0;
            }
            $.post('{{ route('taxes.tax-status') }}', {
                _token: '{{ csrf_token() }}',
                id: el.value,
                status: status
            }, function(data) {
                if (data == 1) {
                    AIZ.plugins.notify('success', '{{ translate('Tax status updated successfully') }}');
                } else {
                    AIZ.plugins.notify('danger', '{{ translate('Something went wrong') }}');
                }
            });
        }

        document.getElementById("type").addEventListener('change', () => {
            var category = document.querySelector("[data-id='category_id']");
            var service = document.querySelector("[data-id='service_id']");

            if (document.getElementById("type").value === "physical") {
                category.classList.remove("d-none");
                category.classList.add("d-flex");
                category.parentElement.classList.remove("d-none");
                category.parentElement.classList.add("d-flex");

                service.classList.remove("d-flex");
                service.classList.add("d-none");
                service.parentElement.classList.remove("d-flex");
                service.parentElement.classList.add("d-none");
            } else {
                category.classList.remove("d-flex");
                category.classList.add("d-none");
                category.parentElement.classList.remove("d-flex");
                category.parentElement.classList.add("d-none");

                service.classList.remove("d-none");
                service.classList.add("d-flex");
                service.parentElement.classList.remove("d-none");
                service.parentElement.classList.add("d-flex");
            }
        });
    </script>
@endsection
