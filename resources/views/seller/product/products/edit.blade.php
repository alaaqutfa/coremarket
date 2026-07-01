@extends('seller.layouts.app')

@section('panel_content')

    <div class="aiz-titlebar mt-2 mb-4">
        <div class="row align-items-center">
            <div class="col-md-6">
                <h1 class="h3">{{ translate('Update your product') }}</h1>
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

    <form class="" action="{{ route('seller.products.update', $product->id) }}" method="POST"
        enctype="multipart/form-data" id="choice_form">
        <div class="row gutters-5">
            <div class="col-lg-12">
                @csrf
                <input name="_method" type="hidden" value="POST">
                <input type="hidden" name="lang" value="{{ $lang }}">
                <input type="hidden" name="id" value="{{ $product->id }}">
                <input type="hidden" name="slug" value="{{ $product->slug }}">
                {{-- <input type="hidden" name="category_id" value="{{ $product->category_id }}" /> --}}
                <input type="hidden" name="added_by" value="seller">
                <!-- General -->
                <div class="card">
                    <ul class="nav nav-tabs nav-fill language-bar">
                        @foreach (get_all_active_language() as $key => $language)
                            <li class="nav-item">
                                <a class="nav-link text-reset @if ($language->code == $lang) active @endif py-3"
                                    href="{{ route('seller.products.edit', ['id' => $product->id, 'lang' => $language->code]) }}">
                                    <img src="{{ static_asset('assets/img/flags/' . $language->code . '.png') }}"
                                        height="11" class="mr-1">
                                    <span>{{ $language->name }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                    <div class="card-body">
                        <!-- Name -->
                        <div class="form-group row">
                            <label class="col-lg-3 col-from-label">{{ translate('Product Name') }} <i
                                    class="las la-language text-danger"
                                    title="{{ translate('Translatable') }}"></i></label>
                            <div class="col-lg-8">
                                <input type="text" class="form-control" name="name"
                                    placeholder="{{ translate('Product Name') }}"
                                    value="{{ $product->getTranslation('name', $lang) }}" required>
                            </div>
                        </div>
                        <!-- Categories -->
                        <div class="Categories">
                            @php
                                $hasSubCategories = true;
                                $old_categories = $product->categories()->pluck('category_id')->toArray();
                            @endphp
                            @foreach ($categories as $category)
                                @if ($category->childrenCategories->isNotEmpty())
                                    @php
                                        $hasSubCategories = true;
                                    @endphp
                                    @break
                                @endif
                            @endforeach

                            @if ($hasSubCategories)
                                @php
                                    $old_category_id = $product->categories()->pluck('category_id')->first();
                                @endphp
                                <div class="form-group row">
                                    <label class="col-md-3 col-from-label">
                                        {{ translate('Product Category') }}
                                        <span class="text-danger">*</span>
                                    </label>
                                    <div id="categories-body" class="col-md-8">
                                        {{-- @foreach ($categories as $category)
                                            @foreach ($category->childrenCategories as $childCategory)
                                                @include('backend.product.products.child_category', [
                                                    'child_category' => $childCategory,
                                                ])
                                            @endforeach
                                        @endforeach --}}
                                        @foreach ($categories as $category)
                                            @if ($category->parent_id == 0)
                                                <div
                                                    class="input-group w-100 d-flex justify-content-between align-items-center">
                                                    <label for="category_id_{{ $category->id }}" class="mb-0">
                                                        <input type="checkbox" name="category_ids[]" id="category_ids_{{ $category->id }}" value="{{ $category->id }}" />
                                                        {{ $category->getTranslation('name') }}
                                                    </label>
                                                    <input type="radio" name="category_id"
                                                        id="category_id_{{ $category->id }}"
                                                        value="{{ $category->id }}" />
                                                    <input type="hidden" name="category_ids[]" id="category_ids_{{ $category->id }}" value="{{ $category->id }}" />
                                                </div>
                                            @endif
                                            @foreach ($category->childrenCategories as $childCategory)
                                                @include('backend.product.products.child_category', [
                                                    'parent_category' => $category->id,
                                                    'child_category' => $childCategory,
                                                ])
                                            @endforeach
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>
                        <!-- Short Description -->
                        <div class="form-group row">
                            <label class="col-md-3 col-from-label">{{ translate('short description') }}</label>
                            <div class="col-md-8">
                                <textarea name="meta_description" rows="8" class="aiz-text-editor">{{ $product->meta_description }}</textarea>
                            </div>
                        </div>
                        <!-- Brand -->
                        @if (\App\Models\Brand::count() > 0)
                            <div class="form-group row" id="brand">
                                <label class="col-lg-3 col-from-label">{{ translate('Brand') }}</label>
                                <div class="col-lg-8">
                                    <select class="form-control aiz-selectpicker" name="brand_id" id="brand_id">
                                        <option value="">{{ translate('Select Brand') }}</option>
                                        @foreach (\App\Models\Brand::all() as $brand)
                                            <option value="{{ $brand->id }}"
                                                @if ($product->brand_id == $brand->id) selected @endif>
                                                {{ $brand->getTranslation('name') }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        @endif
                        <!-- Minimum Purchase Qty -->
                        <div class="form-group row">
                            <label class="col-lg-3 col-from-label">{{ translate('Minimum Purchase Qty') }}</label>
                            <div class="col-lg-8">
                                <input type="number" lang="en" class="form-control" name="min_qty"
                                    value="{{ $product->min_qty }}" min="1" required>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Product Images -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0 h6">{{ translate('Product Images') }}</h5>
                    </div>
                    <div class="card-body">
                        <div class="form-group row">
                            <label class="col-md-3 col-form-label"
                                for="signinSrEmail">{{ translate('Gallery Images') }}</label>
                            <div class="col-md-8">
                                <div class="input-group" data-toggle="aizuploader" data-type="image" data-multiple="true">
                                    <div class="input-group-prepend">
                                        <div class="input-group-text bg-soft-secondary font-weight-medium">
                                            {{ translate('Browse') }}</div>
                                    </div>
                                    <div class="form-control file-amount">{{ translate('Choose File') }}</div>
                                    <input type="hidden" name="photos" value="{{ $product->photos }}"
                                        class="selected-files">
                                </div>
                                <div class="file-preview box sm">
                                </div>
                                <small
                                    class="text-muted">{{ translate('These images are visible in product details page gallery. Minimum dimensions required: 900px width X 900px height.') }}</small>
                            </div>
                        </div>
                        <div class="form-group row">
                            <label class="col-md-3 col-form-label"
                                for="signinSrEmail">{{ translate('Thumbnail Image') }}</label>
                            <div class="col-md-8">
                                <div class="input-group" data-toggle="aizuploader" data-type="image">
                                    <div class="input-group-prepend">
                                        <div class="input-group-text bg-soft-secondary font-weight-medium">
                                            {{ translate('Browse') }}</div>
                                    </div>
                                    <div class="form-control file-amount">{{ translate('Choose File') }}</div>
                                    <input type="hidden" name="thumbnail_img" value="{{ $product->thumbnail_img }}"
                                        class="selected-files">
                                </div>
                                <div class="file-preview box sm">
                                </div>
                                <small
                                    class="text-muted">{{ translate('This image is visible in all product box. Minimum dimensions required: 195px width X 195px height. Keep some blank space around main object of your image as we had to crop some edge in different devices to make it responsive.') }}</small>
                            </div>
                        </div>
                        <div class="form-group row">
                            <label class="col-md-3 col-form-label"
                                for="signinSrEmail">{{ translate('Meta Image') }}</label>
                            <div class="col-md-8">
                                <div class="input-group" data-toggle="aizuploader" data-type="image"
                                    data-multiple="true">
                                    <div class="input-group-prepend">
                                        <div class="input-group-text bg-soft-secondary font-weight-medium">
                                            {{ translate('Browse') }}
                                        </div>
                                    </div>
                                    <div class="form-control file-amount">{{ translate('Choose File') }}</div>
                                    <input type="hidden" name="meta_img" value="{{ $product->meta_img }}"
                                        class="selected-files">
                                </div>
                                <div class="file-preview box sm">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Product price + stock -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0 h6">{{ translate('Product price + stock') }}</h5>
                    </div>
                    <div class="card-body">
                        <div class="form-group row">
                            <label class="col-lg-3 col-from-label">{{ translate('Unit price') }}</label>
                            <div class="col-lg-6">
                                <input type="text" placeholder="{{ translate('Unit price') }}" name="unit_price"
                                    class="form-control" value="{{ $product->unit_price }}" required>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label class="col-md-3 col-from-label">{{ translate('Cost price') }} <span
                                    class="text-danger">*</span></label>
                            <div class="col-md-6">
                                <input type="number" min="0" step="0.01"
                                    placeholder="{{ translate('Cost price') }}" name="wholesale_price"
                                    class="form-control" value="{{ $product->wholesale_price }}" required>
                            </div>
                        </div>

                        @php
                            $date_range = '';
                            if ($product->discount_start_date) {
                                $start_date = date('d-m-Y H:i:s', $product->discount_start_date);
                                $end_date = date('d-m-Y H:i:s', $product->discount_end_date);
                                $date_range = $start_date . ' to ' . $end_date;
                            }
                        @endphp

                        <div class="form-group row">
                            <label class="col-lg-3 col-from-label"
                                for="start_date">{{ translate('Discount Date Range') }}</label>
                            <div class="col-lg-9">
                                <input type="text" class="form-control aiz-date-range" value="{{ $date_range }}"
                                    name="date_range" placeholder="{{ translate('Select Date') }}"
                                    data-time-picker="true" data-format="DD-MM-Y HH:mm:ss" data-separator=" to "
                                    autocomplete="off">
                            </div>
                        </div>

                        <div class="form-group row">
                            <label class="col-lg-3 col-from-label">{{ translate('Discount') }}</label>
                            <div class="col-lg-6">
                                <input type="number" lang="en" min="0" step="0.01"
                                    placeholder="{{ translate('Discount') }}" name="discount" class="form-control"
                                    value="{{ $product->discount }}" required>
                            </div>
                            <div class="col-lg-3">
                                <select class="form-control aiz-selectpicker" name="discount_type" required>
                                    <option value="amount" <?php if ($product->discount_type == 'amount') {
                                        echo 'selected';
                                    } ?>>
                                        {{ translate('Flat') }}</option>
                                    <option value="percent" <?php if ($product->discount_type == 'percent') {
                                        echo 'selected';
                                    } ?>>
                                        {{ translate('Percent') }}</option>
                                </select>
                            </div>
                        </div>

                        <div id="show-hide-div">
                            <div class="form-group row">
                                <label class="col-lg-3 col-from-label">{{ translate('Quantity') }}</label>
                                <div class="col-lg-6">
                                    <input type="number" lang="en" value="{{ $product->stocks->first()->qty }}"
                                        step="1" placeholder="{{ translate('Quantity') }}" name="current_stock"
                                        class="form-control">
                                </div>
                            </div>
                        </div>
                        {{-- <div class="form-group row">
                        <label class="col-md-3 col-from-label">
                            {{translate('External link')}}
                        </label>
                        <div class="col-md-9">
                            <input type="text" placeholder="{{ translate('External link') }}" name="external_link" value="{{ $product->external_link }}" class="form-control">
                            <small class="text-muted">{{translate('Leave it blank if you do not use external site link')}}</small>
                        </div>
                    </div> --}}
                        {{-- <div class="form-group row">
                        <label class="col-md-3 col-from-label">
                            {{translate('External link button text')}}
                        </label>
                        <div class="col-md-9">
                            <input type="text" placeholder="{{ translate('External link button text') }}" name="external_link_btn" value="{{ $product->external_link_btn }}" class="form-control">
                            <small class="text-muted">{{translate('Leave it blank if you do not use external site link')}}</small>
                        </div>
                    </div> --}}
                        <div class="sku_combination" id="sku_combination">

                        </div>
                    </div>
                </div>
                <!-- Product Description -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0 h6">{{ translate('Product Description') }}</h5>
                    </div>
                    <div class="card-body">
                        <div class="form-group row">
                            <div class="col-md-12">
                                <textarea class="aiz-text-editor w-100" name="description">{{ old('description', $product->description) }}</textarea>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- VAT & Tax -->
                @foreach (\App\Models\Tax::where('tax_status', 1)->where('type', 'physical')->get() as $tax)
                    {{-- ->where('tax_category', $product->category_id) --}}
                    <div class="tax_{{ $tax->id }}">
                        <input type="hidden" name="tax_name" value="{{ $tax->name }}">
                        <input type="hidden" name="type" value="{{ $tax->type }}">
                        <input type="hidden" name="tax_id[]" value="{{ $tax->id }}">
                        <input type="hidden" name="tax_value[]" value="{{ $tax->tax_value }}">
                        <input type="hidden" name="tax_types[]" value="{{ $tax->tax_type }}">
                    </div>
                @endforeach
            </div>
            <div class="col-lg-4 d-none">
                <!-- SEO Meta Tags -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0 h6">{{ translate('SEO Meta Tags') }}</h5>
                    </div>
                    <div class="card-body">
                        <div class="form-group row">
                            <label class="col-md-3 col-from-label">{{ translate('Meta Title') }}</label>
                            <div class="col-md-8">
                                <input type="text" class="form-control" name="meta_title"
                                    value="{{ $product->meta_title }}" placeholder="{{ translate('Meta Title') }}">
                            </div>
                        </div>
                        <script>
                            document.getElementById('product_name').addEventListener('input', function() {
                                document.getElementById('meta_title').value = this.value;
                            });
                        </script>
                    </div>
                </div>
                <!-- Low Stock Quantity Warning -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0 h6">{{ translate('Low Stock Quantity Warning') }}</h5>
                    </div>
                    <div class="card-body">
                        <div class="form-group mb-3">
                            <label for="name">
                                {{ translate('Quantity') }}
                            </label>
                            <input type="number" name="low_stock_quantity" value="{{ $product->low_stock_quantity }}"
                                min="0" step="1" class="form-control">
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12">
                <div class="mar-all text-right mb-2">
                    <button type="submit" name="button" value="publish"
                        class="btn btn-primary">{{ translate('Update Product') }}</button>
                </div>
            </div>
        </div>
    </form>

@endsection

@section('modal')
    <!-- Frequently Bought Product Select Modal -->
    @include('modals.product_select_modal')
@endsection

@section('script')
    <!-- Treeview js -->
    <script src="{{ static_asset('assets/js/hummingbird-treeview.js') }}"></script>

    <script type="text/javascript">
        $(document).ready(function() {
            show_hide_shipping_div();
            var main_id = '{{ $product->category_id != null ? $product->category_id : 0 }}';
            var selected_ids = '{{ implode(",", $old_categories) }}';
            if (selected_ids != '') {
                const myArray = selected_ids.split(",");
                for (let i = 0; i < myArray.length; i++) {
                    const element = myArray[i];
                    $('#categories-body input:checkbox#category_ids_' + element).prop('checked', true);
                }
            }
            $('#categories-body input:radio[value=' + main_id + ']').prop('checked', true);
            $('.childCategory').slideUp();
            $('.parent_category_' + main_id).slideDown();
            $('input:radio[name="category_id"]').on("change", function() {
                $('.childCategory').slideUp();
                $('.childCategory input:checkbox').prop('checked', false);
                let $this = $(this);
                $('.parent_category_' + $this.val()).slideDown();
            });
            fq_bought_product_selection_type();
        });

        $("[name=shipping_type]").on("change", function() {
            show_hide_shipping_div();
        });

        function show_hide_shipping_div() {
            var shipping_val = $("[name=shipping_type]:checked").val();

            $(".flat_rate_shipping_div").hide();

            if (shipping_val == 'flat_rate') {
                $(".flat_rate_shipping_div").show();
            }
        }


        function add_more_customer_choice_option(i, name) {
            $.ajax({
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                },
                type: "POST",
                url: '{{ route('seller.products.add-more-choice-option') }}',
                data: {
                    attribute_id: i
                },
                success: function(data) {
                    var obj = JSON.parse(data);
                    $('#customer_choice_options').append('\
                                    <div class="form-group row">\
                                        <div class="col-md-3">\
                                            <input type="hidden" name="choice_no[]" value="' + i + '">\
                                            <input type="text" class="form-control" name="choice[]" value="' + name +
                        '" placeholder="{{ translate('Choice Title') }}" readonly>\
                                        </div>\
                                        <div class="col-md-8">\
                                            <select class="form-control aiz-selectpicker attribute_choice" data-live-search="true" name="choice_options_' +
                        i + '[]" multiple>\
                                                ' + obj + '\
                                            </select>\
                                        </div>\
                                    </div>');
                    AIZ.plugins.bootstrapSelect('refresh');
                }
            });


        }

        $('input[name="colors_active"]').on('change', function() {
            if (!$('input[name="colors_active"]').is(':checked')) {
                $('#colors').prop('disabled', true);
                AIZ.plugins.bootstrapSelect('refresh');
            } else {
                $('#colors').prop('disabled', false);
                AIZ.plugins.bootstrapSelect('refresh');
            }
            update_sku();
        });

        $(document).on("change", ".attribute_choice", function() {
            update_sku();
        });

        $('#colors').on('change', function() {
            update_sku();
        });

        function delete_row(em) {
            $(em).closest('.form-group').remove();
            update_sku();
        }

        function delete_variant(em) {
            $(em).closest('.variant').remove();
        }

        function update_sku() {
            $.ajax({
                type: "POST",
                url: '{{ route('seller.products.sku_combination_edit') }}',
                data: $('#choice_form').serialize(),
                success: function(data) {
                    $('#sku_combination').html(data);
                    setTimeout(() => {
                        AIZ.uploader.previewGenerate();
                    }, "2000");
                    if (data.trim().length > 1) {
                        $('#show-hide-div').hide();
                        AIZ.plugins.sectionFooTable('#sku_combination');
                    } else {
                        $('#show-hide-div').show();
                    }
                }
            });
        }

        AIZ.plugins.tagify();


        $(document).ready(function() {
            update_sku();

            $('.remove-files').on('click', function() {
                $(this).parents(".col-md-4").remove();
            });
        });

        $('#choice_attributes').on('change', function() {
            $.each($("#choice_attributes option:selected"), function(j, attribute) {
                flag = false;
                $('input[name="choice_no[]"]').each(function(i, choice_no) {
                    if ($(attribute).val() == $(choice_no).val()) {
                        flag = true;
                    }
                });
                if (!flag) {
                    add_more_customer_choice_option($(attribute).val(), $(attribute).text());
                }
            });

            var str = @php echo $product->attributes @endphp;

            $.each(str, function(index, value) {
                flag = false;
                $.each($("#choice_attributes option:selected"), function(j, attribute) {
                    if (value == $(attribute).val()) {
                        flag = true;
                    }
                });
                if (!flag) {
                    $('input[name="choice_no[]"][value="' + value + '"]').parent().parent().remove();
                }
            });

            update_sku();
        });

        function fq_bought_product_selection_type() {
            var productSelectionType = $("input[name='frequently_bought_selection_type']:checked").val();
            if (productSelectionType == 'product') {
                $('.fq_bought_select_product_div').removeClass('d-none');
                $('.fq_bought_select_category_div').addClass('d-none');
            } else if (productSelectionType == 'category') {
                $('.fq_bought_select_category_div').removeClass('d-none');
                $('.fq_bought_select_product_div').addClass('d-none');
            }
        }

        function showFqBoughtProductModal() {
            $('#fq-bought-product-select-modal').modal('show', {
                backdrop: 'static'
            });
        }

        function filterFqBoughtProduct() {
            var productID = $('input[name=id]').val();
            var searchKey = $('input[name=search_keyword]').val();
            var fqBroughCategory = $('select[name=fq_brough_category]').val();
            $.post('{{ route('seller.product.search') }}', {
                _token: AIZ.data.csrf,
                product_id: productID,
                search_key: searchKey,
                category: fqBroughCategory,
                product_type: "physical"
            }, function(data) {
                $('#product-list').html(data);
                AIZ.plugins.sectionFooTable('#product-list');
            });
        }

        function addFqBoughtProduct() {
            var selectedProducts = [];
            $("input:checkbox[name=fq_bought_product_id]:checked").each(function() {
                selectedProducts.push($(this).val());
            });

            var fqBoughtProductIds = [];
            $("input[name='fq_bought_product_ids[]']").each(function() {
                fqBoughtProductIds.push($(this).val());
            });

            var productIds = selectedProducts.concat(fqBoughtProductIds.filter((item) => selectedProducts.indexOf(item) <
                0))

            $.post('{{ route('seller.get-selected-products') }}', {
                _token: AIZ.data.csrf,
                product_ids: productIds
            }, function(data) {
                $('#fq-bought-product-select-modal').modal('hide');
                $('#selected-fq-bought-products').html(data);
                AIZ.plugins.sectionFooTable('#selected-fq-bought-products');
            });
        }
    </script>
@endsection
