@extends('frontend.layouts.app')
@section('content')
    
<section class="mb-4 pt-4">
    <div class="container sm-px-0 pt-2">
        <form class="" id="search-form" action="" method="GET">
            <div class="row">
                <!-- Sidebar Filters -->
                <div class="col-xl-3">
                    <div class="aiz-filter-sidebar collapse-sidebar-wrap sidebar-xl sidebar-right z-1035">
                        <div class="overlay overlay-fixed dark c-pointer" data-toggle="class-toggle" data-target=".aiz-filter-sidebar" data-same=".filter-sidebar-thumb"></div>
                        <div class="collapse-sidebar c-scrollbar-light text-left">
                            <div class="d-flex d-xl-none justify-content-between align-items-center pl-3 border-bottom">
                                <h3 class="h6 mb-0 fw-600">{{ translate('Filters') }}</h3>
                                <button type="button" class="btn btn-sm p-2 filter-sidebar-thumb" data-toggle="class-toggle" data-target=".aiz-filter-sidebar" >
                                    <i class="las la-times la-2x"></i>
                                </button>
                            </div>

                            <!-- Categories -->
                            <div class="bg-white border mb-3">
                                <div class="fs-16 fw-700 p-3">
                                    <a href="#collapse_1" class="dropdown-toggle filter-section text-dark d-flex align-items-center justify-content-between" data-toggle="collapse">
                                        {{ translate('Categories')}}
                                    </a>
                                </div>
                                <div class="collapse show" id="collapse_1">
                                    <ul class="p-3 mb-0 list-unstyled">
                                        @if (!isset($category_id))
                                            @foreach ($categories as $category)
                                                <li class="mb-3 text-dark">
                                                    <a class="text-reset fs-14 hov-text-primary" href="{{ route('products.category', $category->slug) }}">
                                                        {{ $category->getTranslation('name') }}
                                                    </a>
                                                </li>
                                            @endforeach
                                        @else
                                            <li class="mb-3">
                                                <a class="text-reset fs-14 fw-600 hov-text-primary" href="{{ route('search') }}">
                                                    <i class="las la-angle-left"></i>
                                                    {{ translate('All Categories')}}
                                                </a>
                                            </li>
                                            
                                            @if ($category->parent_id != 0)
                                                <li class="mb-3">
                                                    <a class="text-reset fs-14 fw-600 hov-text-primary" href="{{ route('products.category', get_single_category($category->parent_id)->slug) }}">
                                                        <i class="las la-angle-left"></i>
                                                        {{ get_single_category($category->parent_id)->getTranslation('name') }}
                                                    </a>
                                                </li>
                                            @endif
                                            <li class="mb-3">
                                                <a class="text-reset fs-14 fw-600 hov-text-primary" href="{{ route('products.category', $category->slug) }}">
                                                    <i class="las la-angle-left"></i>
                                                    {{ $category->getTranslation('name') }}
                                                </a>
                                            </li>
                                            @foreach ($category->childrenCategories as $key => $immediate_children_category)
                                                <li class="ml-4 mb-3">
                                                    <a class="text-reset fs-14 hov-text-primary" href="{{ route('products.category', $immediate_children_category->slug) }}">
                                                        {{ $immediate_children_category->getTranslation('name') }}
                                                    </a>
                                                </li>
                                            @endforeach
                                        @endif
                                    </ul>
                                </div>
                            </div>

                            <!-- Price range -->
                            <div class="bg-white border mb-3">
                                <div class="fs-16 fw-700 p-3">
                                    {{ translate('Price range')}}
                                </div>
                                <div class="p-3 mr-3">
                                    @php
                                        $product_count = get_products_count()
                                    @endphp
                                    <div class="aiz-range-slider">
                                        <div
                                            id="input-slider-range"
                                            data-range-value-min="@if($product_count < 1) 0 @else {{ get_product_min_unit_price() }} @endif"
                                            data-range-value-max="@if($product_count < 1) 0 @else {{ get_product_max_unit_price() }} @endif"
                                        ></div>

                                        <div class="row mt-2">
                                            <div class="col-6">
                                                <span class="range-slider-value value-low fs-14 fw-600 opacity-70"
                                                    @if (isset($min_price))
                                                        data-range-value-low="{{ $min_price }}"
                                                    @elseif($products->min('unit_price') > 0)
                                                        data-range-value-low="{{ $products->min('unit_price') }}"
                                                    @else
                                                        data-range-value-low="0"
                                                    @endif
                                                    id="input-slider-range-value-low"
                                                ></span>
                                            </div>
                                            <div class="col-6 text-right">
                                                <span class="range-slider-value value-high fs-14 fw-600 opacity-70"
                                                    @if (isset($max_price))
                                                        data-range-value-high="{{ $max_price }}"
                                                    @elseif($products->max('unit_price') > 0)
                                                        data-range-value-high="{{ $products->max('unit_price') }}"
                                                    @else
                                                        data-range-value-high="0"
                                                    @endif
                                                    id="input-slider-range-value-high"
                                                ></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <!-- Hidden Items -->
                                <input type="hidden" name="min_price" value="">
                                <input type="hidden" name="max_price" value="">
                            </div>
                            
                            <!-- Attributes -->
                            @foreach ($attributes as $attribute)
                                <div class="bg-white border mb-3">
                                    <div class="fs-16 fw-700 p-3">
                                        <a href="#" class="dropdown-toggle text-dark filter-section collapsed d-flex align-items-center justify-content-between" 
                                            data-toggle="collapse" data-target="#collapse_{{ str_replace(' ', '_', $attribute->name) }}" style="white-space: normal;">
                                            {{ $attribute->getTranslation('name') }}
                                        </a>
                                    </div>
                                    @php
                                        $show = '';
                                        foreach ($attribute->attribute_values as $attribute_value){
                                            if(in_array($attribute_value->value, $selected_attribute_values)){
                                                $show = 'show';
                                            }
                                        }
                                    @endphp
                                    <div class="collapse {{ $show }}" id="collapse_{{ str_replace(' ', '_', $attribute->name) }}">
                                        <div class="p-3 aiz-checkbox-list">
                                            @foreach ($attribute->attribute_values as $attribute_value)
                                                <label class="aiz-checkbox mb-3">
                                                    <input
                                                        type="checkbox"
                                                        name="selected_attribute_values[]"
                                                        value="{{ $attribute_value->value }}" @if (in_array($attribute_value->value, $selected_attribute_values)) checked @endif
                                                        onchange="filter()"
                                                    >
                                                    <span class="aiz-square-check"></span>
                                                    <span class="fs-14 fw-400 text-dark">{{ $attribute_value->value }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                                
                            <!-- Color -->
                            @if (get_setting('color_filter_activation'))
                                <div class="bg-white border mb-3">
                                    <div class="fs-16 fw-700 p-3">
                                        <a href="#" class="dropdown-toggle text-dark filter-section collapsed d-flex align-items-center justify-content-between" data-toggle="collapse" data-target="#collapse_color">
                                            {{ translate('Filter by color')}}
                                        </a>
                                    </div>
                                    @php
                                        $show = '';
                                        foreach ($colors as $key => $color){
                                            if(isset($selected_color) && $selected_color == $color->code){
                                                $show = 'show';
                                            }
                                        }
                                    @endphp
                                    <div class="collapse {{ $show }}" id="collapse_color">
                                        <div class="p-3 aiz-radio-inline">
                                            @foreach ($colors as $key => $color)
                                            <label class="aiz-megabox pl-0 mr-2" data-toggle="tooltip" data-title="{{ $color->name }}">
                                                <input
                                                    type="radio"
                                                    name="color"
                                                    value="{{ $color->code }}"
                                                    onchange="filter()"
                                                    @if(isset($selected_color) && $selected_color == $color->code) checked @endif
                                                >
                                                <span class="aiz-megabox-elem rounded d-flex align-items-center justify-content-center p-1 mb-2">
                                                    <span class="size-30px d-inline-block rounded" style="background: {{ $color->code }};"></span>
                                                </span>
                                            </label>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
                <!-- Contents -->
                <div class="col-xl-9">
                    
                    
                    <!-- Products -->
                    <div class="px-3">
                        <div class="row gutters-16 row-cols-xxl-4 row-cols-xl-3 row-cols-lg-4 row-cols-md-3 row-cols-2 border-top border-left">
                            @foreach ($products as $key => $product)
                                <div class="col border-right border-bottom has-transition hov-shadow-out z-1">
                                    @include('frontend.'.get_setting('homepage_select').'.partials.product_box_1',['product' => $product])
                                </div>
                            @endforeach
                        </div>
                    </div>
                    {{-- <div class="aiz-pagination mt-4">
                        {{ $products->appends(request()->input())->links() }}
                    </div> --}}
                </div>
            </div>
        </form>
    </div>
</section>
@endsection