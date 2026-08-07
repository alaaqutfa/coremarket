<?php

namespace App\Support;

/**
 * Keeps authentication phone numbers in the legacy country-code + national
 * number shape while applying one safe, international validation rule.
 */
final class InternationalPhone
{
    /**
     * @return array{country_code: string, phone: string, e164: string}|null
     */
    public static function normalize(?string $countryCode, ?string $phone): ?array
    {
        $phone = trim((string) $phone);

        if ($phone === '') {
            return null;
        }

        $countryCode = preg_replace('/[\s+\-().]/', '', (string) $countryCode);
        $phone = preg_replace('/[\s\-().]/', '', $phone);

        if (! is_string($countryCode) || ! is_string($phone)
            || ! preg_match('/^\d{1,4}$/', $countryCode)
            || ! preg_match('/^\d{3,14}$/', $phone)
            || strlen($countryCode.$phone) > 15) {
            return null;
        }

        return [
            'country_code' => $countryCode,
            'phone' => $phone,
            'e164' => '+'.$countryCode.$phone,
        ];
    }
}
