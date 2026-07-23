<?php

namespace Database\Seeders;

use App\Models\PriceList;
use Illuminate\Database\Seeder;

class PriceListSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['name' => 'Retail', 'code' => 'RETAIL', 'type' => 'retail', 'is_default' => true],
            ['name' => 'Wholesale A', 'code' => 'WHOLESALE-A', 'type' => 'wholesale'],
            ['name' => 'Wholesale B', 'code' => 'WHOLESALE-B', 'type' => 'wholesale'],
            ['name' => 'Wholesale C', 'code' => 'WHOLESALE-C', 'type' => 'wholesale'],
            ['name' => 'VIP', 'code' => 'VIP', 'type' => 'vip'],
        ] as $attributes) {
            PriceList::query()->updateOrCreate(
                ['code' => $attributes['code']],
                $attributes + ['pricing_method' => 'fixed_price', 'currency' => 'USD', 'is_active' => true]
            );
        }
    }
}
