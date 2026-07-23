<?php

namespace Tests\Feature;

use App\Services\CoreMarketMoneyService;
use InvalidArgumentException;
use Tests\TestCase;

class CoreMarketCurrencyFoundationTest extends TestCase
{
    public function test_usd_is_the_base_currency_and_money_never_exceeds_two_decimals(): void
    {
        $money = app(CoreMarketMoneyService::class);

        $this->assertSame('USD', $money->baseCurrency());
        $this->assertSame(1.0, $money->baseExchangeRate());
        $this->assertSame('1,234.57 USD', $money->formatMoney('1234.5678'));
        $this->assertSame('1,234.57 USD', coremarket_money('1234.5678'));
        $this->assertSame('1,234.57', coremarket_number('1234.5678', 8));
        $this->assertSame('1.234568', coremarket_quantity('1.2345678'));
        $this->assertSame('0.00 USD', coremarket_money(null));
    }

    public function test_usd_can_be_converted_to_lbp_using_the_reference_rate(): void
    {
        $money = app(CoreMarketMoneyService::class);

        $this->assertSame(89500.0, $money->convertByRates(1, 1, 89500));
        $this->assertSame('89,500.00 LBP', $money->formatMoney(89500, 'LBP'));
        $this->assertSame(1.0, $money->convertByRates(89500, 89500, 1));
    }

    public function test_conversion_rejects_invalid_exchange_rates(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(CoreMarketMoneyService::class)->convertByRates(1, 0, 89500);
    }
}
