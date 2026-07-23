<?php

namespace Tests\Unit;

use App\Services\PurchaseItemPricingService;
use Tests\TestCase;

class PurchaseItemPricingServiceTest extends TestCase
{
    public function test_margin_calculates_regular_price_and_keeps_sale_price_separate(): void
    {
        $pricing = app(PurchaseItemPricingService::class)->calculate([
            'quantity_ordered' => 2,
            'unit_cost' => 8,
            'margin_percent' => 50,
            'sale_price' => 10,
            'tax_enabled' => false,
        ]);

        $this->assertSame(8.0, $pricing['cost_price']);
        $this->assertSame(12.0, $pricing['regular_price']);
        $this->assertSame(10.0, $pricing['sale_price']);
        $this->assertSame(50.0, $pricing['margin_percent']);
        $this->assertSame(16.0, $pricing['line_subtotal']);
        $this->assertSame(16.0, $pricing['line_total']);
    }

    public function test_regular_price_calculates_margin(): void
    {
        $pricing = app(PurchaseItemPricingService::class)->calculate([
            'quantity_ordered' => 1,
            'unit_cost' => 8,
            'regular_price' => 12,
            'tax_enabled' => false,
        ]);

        $this->assertSame(50.0, $pricing['margin_percent']);
        $this->assertSame('regular_price', $pricing['pricing_source']);
    }

    public function test_item_tax_uses_tax_service_snapshot_and_two_decimal_money(): void
    {
        $pricing = app(PurchaseItemPricingService::class)->calculate([
            'quantity_ordered' => 2,
            'unit_cost' => 100.127,
            'regular_price' => 150,
            'tax_enabled' => true,
            'tax_rate' => 11,
        ]);

        $this->assertSame(200.26, $pricing['line_subtotal']);
        $this->assertSame(22.03, $pricing['tax_amount']);
        $this->assertSame(222.29, $pricing['line_total']);
        $this->assertSame(11.0, $pricing['tax_snapshot']['rate']);
        $this->assertSame('purchase_item', $pricing['tax_snapshot']['source']);
    }

    public function test_disabled_tax_is_zero(): void
    {
        $pricing = app(PurchaseItemPricingService::class)->calculate([
            'quantity_ordered' => 1,
            'unit_cost' => 100,
            'regular_price' => 125,
            'tax_enabled' => false,
            'tax_rate' => 11,
        ]);

        $this->assertSame(0.0, $pricing['tax_amount']);
        $this->assertFalse($pricing['tax_snapshot']['taxable']);
    }
}
