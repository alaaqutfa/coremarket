<?php

namespace Tests\Feature;

use Tests\TestCase;

class HomeSliderMediaTest extends TestCase
{
    public function test_all_homepage_themes_reuse_the_uncropped_slider_media_partial(): void
    {
        foreach (['classic', 'metro', 'minima', 'megamart', 'reclassic'] as $theme) {
            $contents = file_get_contents(resource_path("views/frontend/{$theme}/index.blade.php"));

            $this->assertStringContainsString('frontend.partials.home_slider_media', $contents, $theme);
        }
    }

    public function test_slider_media_uses_a_contained_foreground_and_derived_backdrop(): void
    {
        $partial = file_get_contents(resource_path('views/frontend/partials/home_slider_media.blade.php'));
        $styles = file_get_contents(public_path('assets/css/aiz-core.css'));

        $this->assertStringContainsString('home-slider-media-backdrop', $partial);
        $this->assertStringContainsString('home-slider-media-foreground', $partial);
        $this->assertStringContainsString('object-fit: contain', $styles);
        $this->assertStringContainsString('filter: blur(18px)', $styles);
    }
}
