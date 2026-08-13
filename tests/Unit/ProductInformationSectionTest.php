<?php

namespace Tests\Unit;

use App;
use App\Models\ProductInformationSection;
use App\Models\ProductInformationSectionTranslation;
use Tests\TestCase;

class ProductInformationSectionTest extends TestCase
{
    public function test_section_uses_current_language_then_default_language_as_fallback(): void
    {
        $section = new ProductInformationSection();
        $section->setRelation('translations', collect([
            new ProductInformationSectionTranslation(['lang' => 'en', 'title' => 'Ingredients', 'content' => '<p>Chicken</p>']),
            new ProductInformationSectionTranslation(['lang' => 'ar', 'title' => 'المكونات', 'content' => '<p>دجاج</p>']),
        ]));

        App::setLocale('ar');
        $this->assertSame('المكونات', $section->getTranslation('title'));

        App::setLocale('fr');
        $this->assertSame('Ingredients', $section->getTranslation('title'));
        $this->assertSame('<p>Chicken</p>', $section->getTranslation('content'));
    }
}
