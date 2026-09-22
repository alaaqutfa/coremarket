@if(count($todays_deal_products) > 0)
    <section class="">
        <div class="container">
            @php
                $lang = get_system_language()->code;
                $todays_deal_banner = get_setting('todays_deal_banner', null, $lang);
                $todays_deal_banner_small = get_setting('todays_deal_banner_small', null, $lang);
            @endphp
            <!-- Banner -->
            @if ($todays_deal_banner != null || $todays_deal_banner_small != null)
                <div class="overflow-hidden d-none d-md-block">
                    <img src="{{ static_asset('assets/img/placeholder-rect.jpg') }}"
                        data-src="{{ uploaded_asset($todays_deal_banner) }}"
                        alt="{{ env('APP_NAME') }} promo" class="lazyload img-fit h-100 has-transition"
                        onerror="this.onerror=null;this.src='{{ static_asset('assets/img/placeholder-rect.jpg') }}';">
                </div>
                <div class="overflow-hidden d-md-none">
                    <img src="{{ static_asset('assets/img/placeholder-rect.jpg') }}"
                        data-src="{{ $todays_deal_banner_small != null ? uploaded_asset($todays_deal_banner_small) : uploaded_asset($todays_deal_banner) }}"
                        alt="{{ env('APP_NAME') }} promo" class="lazyload img-fit h-100 has-transition"
                        onerror="this.onerror=null;this.src='{{ static_asset('assets/img/placeholder-rect.jpg') }}';">
                </div>
            @endif
            <!-- Products -->
            @php
                $todays_deal_banner_text_color =  ((get_setting('todays_deal_banner_text_color') == 'light') ||  (get_setting('todays_deal_banner_text_color') == null)) ? 'text-white' : 'text-dark';
            @endphp
            <div class="" style="background-color: {{ get_setting('todays_deal_bg_color', '#3d4666') }}">
                <div class="text-right px-4 px-xl-5 pt-4 pt-md-3">
                    <a href="{{ route('todays-deal') }}" class="fs-12 fw-700 {{ $todays_deal_banner_text_color }} has-transition hov-text-secondary-base">{{ translate('View All') }}</a>
                </div>
                <div class="metro-todays-deal-products c-scrollbar-light overflow-hidden px-3 px-md-5 pb-3 pt-3 pt-md-3 pb-md-5">
                    <div class="h-100 d-flex flex-column justify-content-center">
                        <div class="todays-deal aiz-carousel" data-items="6" data-xxl-items="6" data-xl-items="5" data-lg-items="4" data-md-items="3" data-sm-items="2" data-xs-items="2" data-arrows="true" data-dots="false" data-autoplay="true" data-infinite="true">
                            @foreach ($todays_deal_products as $key => $product)
                                <div class="carousel-box h-100 px-2 px-md-2">
                                    <a href="{{ route('product', $product->slug) }}" class="metro-todays-deal-card h-100 overflow-hidden hov-scale-img mx-auto" title="{{ $product->getTranslation('name') }}">
                                        <!-- Image -->
                                        <div class="metro-todays-deal-image d-flex justify-content-center align-items-center rounded-content overflow-hidden mx-auto">
                                            <img class="lazyload img-fit m-auto has-transition"
                                                style="object-fit: contain !important;"
                                                src="{{ static_asset('assets/img/placeholder.jpg') }}"
                                                data-src="{{ get_image($product->thumbnail) }}"
                                                alt="{{ $product->getTranslation('name') }}"
                                                onerror="this.onerror=null;this.src='{{ static_asset('assets/img/placeholder.jpg') }}';">
                                        </div>
                                        <div class="metro-todays-deal-name mt-3 text-center text-truncate fw-600">
                                            {{ $product->getTranslation('name') }}
                                        </div>
                                        <div class="metro-todays-deal-price mt-2 text-center">
                                            <span class="d-block fw-700">{{ home_discounted_base_price($product) }}</span>
                                            @if(home_base_price($product) != home_discounted_base_price($product))
                                                <del class="d-block fw-400">{{ home_base_price($product) }}</del>
                                            @endif
                                        </div>
                                    </a>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <style>
        .metro-todays-deal-products {
            background: linear-gradient(180deg, rgba(255, 255, 255, .04), rgba(0, 0, 0, .08));
        }

        .metro-todays-deal-card {
            display: block;
            max-width: 210px;
            padding: 14px 12px 16px;
            color: #252b33;
            background: #fff;
            border: 1px solid rgba(0, 0, 0, .08);
            border-radius: 14px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, .1);
        }

        .metro-todays-deal-card:hover {
            color: #252b33;
            background: #fff;
        }

        .metro-todays-deal-image {
            width: min(100%, 168px);
            aspect-ratio: 1;
            background: #fff;
        }

        .metro-todays-deal-image img {
            width: 100%;
            height: 100%;
            padding: 8px;
        }

        .metro-todays-deal-name {
            min-height: 24px;
            font-size: 14px;
            line-height: 24px;
        }

        .metro-todays-deal-price {
            font-size: 15px;
            line-height: 22px;
        }

        .metro-todays-deal-price del {
            color: #8a929b;
            font-size: 12px;
        }

        @media (max-width: 575.98px) {
            .metro-todays-deal-card {
                padding: 10px 8px 12px;
                border-radius: 12px;
            }

            .metro-todays-deal-image {
                width: min(100%, 132px);
            }

            .metro-todays-deal-name {
                margin-top: 10px !important;
                font-size: 12px;
            }
        }
    </style>
@endif
