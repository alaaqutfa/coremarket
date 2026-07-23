<?php

namespace App\Services;

use App\Models\TaxRate;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class CoreMarketTaxService
{
    public function __construct(private CoreMarketMoneyService $money)
    {
    }

    public function getDefaultTaxRate(): ?TaxRate
    {
        $code = trim((string) config('coremarket.tax.default_rate_code'));
        if ($code === '' || ! Schema::hasTable('tax_rates')) {
            return null;
        }

        return TaxRate::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('starts_at')->orWhereDate('starts_at', '<=', today());
            })
            ->where(function ($query) {
                $query->whereNull('ends_at')->orWhereDate('ends_at', '>=', today());
            })
            ->first();
    }

    public function calculateTaxAmount(mixed $baseAmount, mixed $taxRate, bool $taxable = true): float
    {
        if (! $taxable) {
            return 0.0;
        }

        return $this->money->normalizeMoney(
            $this->money->normalizeMoney($baseAmount) * ($this->normalizeTaxRate($taxRate) / 100)
        );
    }

    public function normalizeTaxRate(mixed $taxRate): float
    {
        $rate = $taxRate instanceof TaxRate ? (float) $taxRate->rate : (is_numeric($taxRate) ? (float) $taxRate : 0.0);
        if ($rate < 0 || $rate > 100) {
            throw new InvalidArgumentException('The tax rate must be between 0 and 100.');
        }

        return round($rate, 4, PHP_ROUND_HALF_UP);
    }

    public function snapshot(
        mixed $baseAmount,
        mixed $taxRate,
        bool $taxable = true,
        string $source = 'general'
    ): array {
        $rate = $this->normalizeTaxRate($taxRate);

        return [
            'rate' => $rate,
            'amount' => $this->calculateTaxAmount($baseAmount, $rate, $taxable),
            'taxable' => $taxable,
            'source' => $source,
            'base_amount' => $this->money->normalizeMoney($baseAmount),
        ];
    }
}
