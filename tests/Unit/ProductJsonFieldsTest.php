<?php

namespace Tests\Unit;

use App\Models\Product;
use Tests\TestCase;

class ProductJsonFieldsTest extends TestCase
{
    public function test_nullable_product_json_fields_are_exposed_as_empty_arrays(): void
    {
        $product = new Product([
            'colors' => null,
            'attributes' => null,
            'choice_options' => null,
        ]);

        $this->assertSame([], $product->colorsArray());
        $this->assertSame([], $product->attributesArray());
        $this->assertSame([], $product->choiceOptionsArray());
    }

    public function test_product_json_field_helpers_preserve_valid_values(): void
    {
        $product = new Product([
            'colors' => '["#000000"]',
            'attributes' => '[4]',
            'choice_options' => '[{"attribute_id":4,"values":["Small"]}]',
        ]);

        $this->assertSame(['#000000'], $product->colorsArray());
        $this->assertSame([4], $product->attributesArray());
        $this->assertSame(4, $product->choiceOptionsArray()[0]->attribute_id);
    }
}
