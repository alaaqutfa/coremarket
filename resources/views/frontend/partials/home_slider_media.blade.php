@php
    $homeSliderImage = $slider
        ? my_asset($slider->file_name)
        : static_asset('assets/img/placeholder-rect.jpg');
@endphp

<a class="d-block home-slider-media-link" href="{{ $href }}">
    <div class="home-slider-media {{ $heightClasses }}">
        <div class="home-slider-media-backdrop" style="background-image: url('{{ $homeSliderImage }}');"></div>
        <img class="home-slider-media-foreground" src="{{ $homeSliderImage }}"
            alt="{{ $alt ?? env('APP_NAME') . ' promo' }}"
            onerror="this.onerror=null;this.src='{{ static_asset('assets/img/placeholder-rect.jpg') }}';">
    </div>
</a>
