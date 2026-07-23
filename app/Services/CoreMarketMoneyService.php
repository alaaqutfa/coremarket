<?php

namespace App\Services;

use InvalidArgumentException;

class CoreMarketMoneyService
{
    public function baseCurrency(): string
    {
        return strtoupper((string) config('coremarket.money.base_currency', 'USD'));
    }

    public function baseExchangeRate(): float
    {
        return (float) config('coremarket.money.base_exchange_rate', 1);
    }

    public function formatMoney(mixed $amount, ?string $currency = null): string
    {
        $decimals = max(0, min(2, (int) config('coremarket.money.display_decimals', 2)));

        return $this->formatNumber($amount, $decimals).' '.$this->normalizeCurrency($currency);
    }

    public function formatPrice(mixed $amount, ?string $currency = null): string
    {
        return $this->formatMoney($amount, $currency);
    }

    public function formatNumber(mixed $number, int $decimals = 2): string
    {
        $precision = max(0, min(2, $decimals));

        return number_format($this->numericValue($number), $precision, '.', ',');
    }

    public function formatQuantity(mixed $quantity): string
    {
        $formatted = number_format($this->numericValue($quantity), 6, '.', '');

        return rtrim(rtrim($formatted, '0'), '.') ?: '0';
    }

    public function normalizeMoney(mixed $amount): float
    {
        return round($this->numericValue($amount), 2, PHP_ROUND_HALF_UP);
    }

    public function convertByRates(mixed $amount, mixed $sourceRate, mixed $targetRate): float
    {
        $source = $this->positiveRate($sourceRate, 'source');
        $target = $this->positiveRate($targetRate, 'target');

        return $this->normalizeMoney(($this->numericValue($amount) / $source) * $target);
    }

    private function normalizeCurrency(?string $currency): string
    {
        $code = strtoupper(trim((string) ($currency ?: $this->baseCurrency())));

        return preg_match('/^[A-Z0-9]{2,10}$/', $code) ? $code : $this->baseCurrency();
    }

    private function numericValue(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function positiveRate(mixed $rate, string $name): float
    {
        $value = $this->numericValue($rate);
        if ($value <= 0) {
            throw new InvalidArgumentException("The {$name} currency exchange rate must be greater than zero.");
        }

        return $value;
    }
}
