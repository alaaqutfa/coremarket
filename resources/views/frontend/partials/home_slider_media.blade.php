@php
    $homeSliderImage = $slider
        ? my_asset($slider->file_name)
        : static_asset('assets/img/placeholder-rect.jpg');

    $homeSliderMobileImage = !empty($mobileSliderImageId)
        ? uploaded_asset($mobileSliderImageId)
        : null;
@endphp

<a class="d-block home-slider-media-link" href="{{ $href }}">
    <div class="home-slider-media">
        <picture>
            @if ($homeSliderMobileImage)
                <source media="(max-width: 767.98px)" srcset="{{ $homeSliderMobileImage }}">
            @endif
            <img class="home-slider-media-foreground" src="{{ $homeSliderImage }}"
                alt="{{ $alt ?? env('APP_NAME') . ' promo' }}"
                onerror="this.onerror=null;this.src='{{ static_asset('assets/img/placeholder-rect.jpg') }}';">
        </picture>
    </div>
</a>
