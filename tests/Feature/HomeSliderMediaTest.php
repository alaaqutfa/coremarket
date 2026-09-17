<?php

namespace Tests\Feature;

use App\Http\Resources\V2\SliderCollection;
use Illuminate\Http\Request;
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

    public function test_slider_media_uses_picture_sources_without_a_blurred_backdrop(): void
    {
        $partial = file_get_contents(resource_path('views/frontend/partials/home_slider_media.blade.php'));
        $styles = file_get_contents(public_path('assets/css/aiz-core.css'));

        $this->assertStringContainsString('<picture>', $partial);
        $this->assertStringContainsString('mobileSliderImageId', $partial);
        $this->assertStringContainsString('home-slider-media-foreground', $partial);
        $this->assertStringContainsString('object-fit: contain', $styles);
        $this->assertStringContainsString('aspect-ratio: 1903 / 553', $styles);
        $this->assertStringNotContainsString('filter: blur(18px)', $styles);
    }

    public function test_all_homepage_themes_support_mobile_slider_images_in_their_settings_and_views(): void
    {
        foreach (['classic', 'metro', 'minima', 'megamart', 'reclassic'] as $theme) {
            $view = file_get_contents(resource_path("views/frontend/{$theme}/index.blade.php"));
            $settings = file_get_contents(resource_path("views/backend/website_settings/pages/{$theme}/home_page_edit.blade.php"));

            $this->assertStringContainsString('home_slider_mobile_images', $view, $theme);
            $this->assertStringContainsString('home_slider_mobile_images', $settings, $theme);
        }
    }

    public function test_slider_api_keeps_existing_fields_and_exposes_an_optional_mobile_photo(): void
    {
        $payload = (new SliderCollection(collect([
            ['image' => null, 'mobile_image' => null, 'link' => 'https://example.test/slider'],
        ])))->toArray(new Request());

        $slider = $payload['data']->first();

        $this->assertArrayHasKey('photo', $slider);
        $this->assertArrayHasKey('mobile_photo', $slider);
        $this->assertSame('https://example.test/slider', $slider['url']);
        $this->assertNull($slider['mobile_photo']);
    }
}
