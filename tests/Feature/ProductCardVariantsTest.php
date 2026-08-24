<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ProductCardVariantsTest extends TestCase
{
    public function test_product_card_price_lookup_reuses_the_existing_variant_price_route(): void
    {
        $route = Route::getRoutes()->getByName('products.variant_price');

        $this->assertSame('product/variant-price', $route->uri());
        $this->assertSame(['POST'], $route->methods());
        $this->assertStringContainsString('HomeController@variant_price', $route->getActionName());
    }

    public function test_all_storefront_product_cards_reuse_the_shared_variant_preview_partial(): void
    {
        $templates = [
            'classic/partials/product_box_1.blade.php',
            'minima/partials/product_box_1.blade.php',
            'minima/partials/product_box_2.blade.php',
            'metro/partials/product_box_1.blade.php',
            'metro/partials/product_box_2.blade.php',
            'megamart/partials/product_box_1.blade.php',
            'megamart/partials/product_box_2.blade.php',
            'reclassic/partials/product_box_1.blade.php',
            'reclassic/partials/product_box_2.blade.php',
        ];

        foreach ($templates as $template) {
            $contents = file_get_contents(resource_path('views/frontend/' . $template));

            $this->assertStringContainsString("frontend.partials.product_card_variants", $contents, $template);
        }

        $partial = file_get_contents(resource_path('views/frontend/partials/product_card_variants.blade.php'));
        $this->assertStringContainsString('data-variant-price-url', $partial);
        $this->assertStringNotContainsString('showAddToCartModal', $partial);
    }
}
