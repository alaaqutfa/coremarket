@extends('seller.layouts.app')

@section('panel_content')
    <div class="page-content mx-0">
        <div class="aiz-titlebar mt-2 mb-4">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h1 class="h3">{{ translate('Add Your Request') }}</h1>
                </div>
                <div class="col text-right">
                    <a class="btn btn-xs btn-soft-primary" href="javascript:void(0);" onclick="clearTempdata()">
                        {{ translate('Clear Tempdata') }}
                    </a>
                </div>
            </div>
        </div>

        <!-- Error Meassages -->
        @if ($errors->any())
            <div class="alert alert-danger">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form class="" action="{{ route('seller.request.store') }}" method="POST"  >
            <div class="row gutters-5">
                <div class="col-lg-8">
                    @csrf
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0 h6">{{ translate('Request Information') }}</h5>
                        </div>
                        <div class="card-body">
                            <div class="form-group row">
                                <label class="col-md-3 col-from-label">{{ translate('Request Name') }} <span class="text-danger">*</span></label>
                                <div class="col-md-8">
                                    <input type="text" class="form-control" name="name"
                                        placeholder="{{ translate('Request Name') }}" required>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">

                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0 h6">{{ translate('Request Kind') }}</h5>
                        </div>
                        <div class="card-body">
                            <div class="h-100px overflow-auto c-scrollbar-light">
                                <input type="radio" id="category" name="kind" class="radio-button" value="category" checked>
                                <label for="category" class="radio-label">{{ translate('Category') }}</label>
                                <br>
                                <input type="radio" id="brand" name="kind" class="radio-button" value="brand">
                                <label for="brand" class="radio-label">{{ translate('Brand') }}</label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="mar-all text-right mb-2">
                        <button type="submit" name="button" value="publish"
                            class="btn btn-primary">{{ translate('Upload Request') }}</button>
                    </div>
                </div>
            </div>

        </form>
    </div>
@endsection

