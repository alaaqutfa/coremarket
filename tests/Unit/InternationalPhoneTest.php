<?php

namespace Tests\Unit;

use App\Support\InternationalPhone;
use Tests\TestCase;

class InternationalPhoneTest extends TestCase
{
    public function test_it_normalizes_international_numbers_without_country_restrictions(): void
    {
        $lebanon = InternationalPhone::normalize('+961', '03 123 456');
        $iraq = InternationalPhone::normalize('964', '770-123-4567');

        $this->assertSame(['country_code' => '961', 'phone' => '03123456', 'e164' => '+96103123456'], $lebanon);
        $this->assertSame(['country_code' => '964', 'phone' => '7701234567', 'e164' => '+9647701234567'], $iraq);
    }

    public function test_it_allows_an_omitted_phone_and_rejects_invalid_values(): void
    {
        $this->assertNull(InternationalPhone::normalize(null, null));
        $this->assertNull(InternationalPhone::normalize('961', 'abc'));
        $this->assertNull(InternationalPhone::normalize(null, '03123456'));
        $this->assertNull(InternationalPhone::normalize('961', str_repeat('1', 13)));
    }
}
