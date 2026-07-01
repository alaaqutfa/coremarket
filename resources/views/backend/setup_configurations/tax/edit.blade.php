@extends('backend.layouts.app')

@section('content')
    <div class="aiz-titlebar text-left mt-2 mb-3">
        <h5 class="mb-0 h6">{{ translate('Tax Information') }}</h5>
    </div>

    <div class="row">
        <div class="col-lg-6 mx-auto">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0 h6">{{ translate('Update Tax Info') }}</h5>
                </div>
                <div class="card-body p-0">
                    <form class="p-4" action="{{ route('tax.update', $tax->id) }}" method="POST">
                        @method('PATCH')
                        @csrf
                        <div class="form-group row">
                            <div class="col-lg-3 mb-2">
                                <label class="control-label">{{ translate('Name') }}</label>
                            </div>
                            <div class="col-lg-9 mb-2">
                                <input type="text" class="form-control" name="name"
                                    placeholder="{{ translate('Name') }}" value="{{ $tax->name }}" required>
                            </div>
                            <label class="col-sm-3 mb-2 control-label" for="type">
                                {{ translate('Product Type') }}
                            </label>
                            <div class="col-sm-9 mb-2">
                                <select class="form-control aiz-selectpicker" name="type" id="type">
                                    <option value="physical" @if ($tax->type == 'physical') selected @endif>
                                        {{ translate('Physical') }}</option>
                                    <option value="digital" @if ($tax->type == 'digital') selected @endif>
                                        {{ translate('Digital') }}</option>
                                </select>
                            </div>
                            <label class="col-sm-3 mb-2 control-label" for="category_id">
                                {{ translate('Category Type') }}
                            </label>
                            <div class="col-sm-9 mb-2">
                                <select
                                    class="form-control @if ($tax->type == 'digital') d-none @endif aiz-selectpicker"
                                    id="category_id" name="category_id">
                                    @foreach ($categories as $category)
                                        <option value="{{ $category->id }}"
                                            @if ($tax->tax_category == $category->id) selected @endif>{{ $category->name }}
                                        </option>
                                    @endforeach
                                </select>
                                <select
                                    class="form-control @if ($tax->type == 'physical') d-none @endif aiz-selectpicker"
                                    id="service_id" name="service_id">
                                    @foreach ($services as $service)
                                        <option value="{{ $service->id }}"
                                            @if ($tax->tax_category == $service->id) selected @endif>{{ $service->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <label class="col-sm-3 mb-2 control-label" for="tax_type">
                                {{ translate('Tax Type') }}
                            </label>
                            <div class="col-sm-9 mb-2">
                                <select class="form-control aiz-selectpicker" name="tax_type">
                                    <option value="amount" @if ($tax->tax_type == 'amount') selected @endif>
                                        {{ translate('Flat') }}</option>
                                    <option value="percent" @if ($tax->tax_type == 'percent') selected @endif>
                                        {{ translate('Percent') }}</option>
                                </select>
                            </div>
                            <label class="col-sm-3 mb-2 control-label" for="tax_value">
                                {{ translate('Value') }}
                            </label>
                            <div class="col-sm-9 mb-2">
                                <input type="number" placeholder="{{ translate('Value') }}" id="tax_value"
                                    name="tax_value" class="form-control" value="{{ $tax->tax_value }}" required>
                            </div>
                        </div>

                        <div class="form-group mb-0 text-right">
                            <button type="submit" class="btn btn-sm btn-primary">{{ translate('Save') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script')
    <script>
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
