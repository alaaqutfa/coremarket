<div class="bg-white mb-4 border p-3 p-sm-4">
    <!-- Tabs -->
    <div class="nav aiz-nav-tabs">
        <a href="#tab_default_1" data-toggle="tab"
            class="mr-5 pb-2 fs-16 fw-700 text-reset active show">{{ translate('Description') }}</a>
        @if ($detailedProduct->video_link != null)
            <a href="#tab_default_2" data-toggle="tab"
                class="mr-5 pb-2 fs-16 fw-700 text-reset">{{ translate('Video') }}</a>
        @endif
        @if ($detailedProduct->pdf != null)
            <a href="#tab_default_3" data-toggle="tab"
                class="mr-5 pb-2 fs-16 fw-700 text-reset">{{ translate('Downloads') }}</a>
        @endif
    </div>

    <!-- Description -->
    <div class="tab-content pt-0">
        <!-- Description -->
        <div class="tab-pane fade active show" id="tab_default_1">
            <div class="py-5">
                <div class="mw-100 overflow-hidden text-left aiz-editor-data">
                    <?php echo $detailedProduct->getTranslation('description'); ?>
                </div>
            </div>
            @php
                $informationSections = $detailedProduct->informationSections
                    ->where('is_active', true)
                    ->filter(function ($section) {
                        return filled($section->getTranslation('title')) && filled(trim(strip_tags((string) $section->getTranslation('content'))));
                    });
            @endphp
            @if ($informationSections->isNotEmpty())
                <div class="accordion pb-4" id="product-information-sections-{{ $detailedProduct->id }}">
                    @foreach ($informationSections as $section)
                        <div class="card border mb-2">
                            <div class="card-header bg-white p-0" id="product-information-heading-{{ $section->id }}">
                                <button class="btn btn-link btn-block text-left text-reset fw-700 py-3 px-4" type="button" data-toggle="collapse" data-target="#product-information-content-{{ $section->id }}" aria-expanded="false" aria-controls="product-information-content-{{ $section->id }}">
                                    {{ $section->getTranslation('title') }}
                                    <span class="float-right">+</span>
                                </button>
                            </div>
                            <div id="product-information-content-{{ $section->id }}" class="collapse" aria-labelledby="product-information-heading-{{ $section->id }}" data-parent="#product-information-sections-{{ $detailedProduct->id }}">
                                <div class="card-body aiz-editor-data mw-100 overflow-hidden text-left">
                                    <?php echo $section->getTranslation('content'); ?>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <!-- Video -->
        <div class="tab-pane fade" id="tab_default_2">
            <div class="py-5">
                <div class="embed-responsive embed-responsive-16by9">
                    @if ($detailedProduct->video_provider == 'youtube' && isset(explode('=', $detailedProduct->video_link)[1]))
                        <iframe class="embed-responsive-item"
                            src="https://www.youtube.com/embed/{{ get_url_params($detailedProduct->video_link, 'v') }}"></iframe>
                    @elseif ($detailedProduct->video_provider == 'dailymotion' && isset(explode('video/', $detailedProduct->video_link)[1]))
                        <iframe class="embed-responsive-item"
                            src="https://www.dailymotion.com/embed/video/{{ explode('video/', $detailedProduct->video_link)[1] }}"></iframe>
                    @elseif ($detailedProduct->video_provider == 'vimeo' && isset(explode('vimeo.com/', $detailedProduct->video_link)[1]))
                        <iframe
                            src="https://player.vimeo.com/video/{{ explode('vimeo.com/', $detailedProduct->video_link)[1] }}"
                            width="500" height="281" frameborder="0" webkitallowfullscreen
                            mozallowfullscreen allowfullscreen></iframe>
                    @endif
                </div>
            </div>
        </div>
        
        <!-- Download -->
        <div class="tab-pane fade" id="tab_default_3">
            <div class="py-5 text-center ">
                <a href="{{ uploaded_asset($detailedProduct->pdf) }}"
                    class="btn btn-primary">{{ translate('Download') }}</a>
            </div>
        </div>
    </div>
</div>
