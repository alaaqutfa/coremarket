<?php

namespace Tests\Feature;

use App\Models\TaxRate;
use App\Services\CoreMarketTaxService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CoreMarketTaxFoundationTest extends TestCase
{
    public function test_tax_service_calculates_and_snapshots_eleven_percent_tax(): void
    {
        $tax = app(CoreMarketTaxService::class);

        $this->assertSame(11.0, $tax->calculateTaxAmount(100, 11));
        $this->assertSame(0.0, $tax->calculateTaxAmount(100, 11, false));
        $this->assertSame([
            'rate' => 11.0,
            'amount' => 13.58,
            'taxable' => true,
            'source' => 'general_tax',
            'base_amount' => 123.45,
        ], $tax->snapshot(123.45, 11, true, 'general_tax'));
    }

    public function test_configured_active_tax_rate_can_be_resolved_as_default(): void
    {
        DB::beginTransaction();
        try {
            $code = 'VAT11-'.uniqid();
            TaxRate::query()->create([
                'name' => 'Lebanon VAT 11%',
                'code' => $code,
                'rate' => 11,
                'tax_type' => 'vat',
                'calculation_type' => 'percentage',
                'price_mode' => 'exclusive',
                'is_active' => true,
            ]);
            config()->set('coremarket.tax.default_rate_code', $code);

            $rate = app(CoreMarketTaxService::class)->getDefaultTaxRate();

            $this->assertNotNull($rate);
            $this->assertSame($code, $rate->code);
            $this->assertSame('11.0000', $rate->rate);
        } finally {
            DB::rollBack();
        }
    }
}
