<?php

namespace App\Services;

class CoreMarketCustomerAccountFeatureService
{
    public function enabled(): bool
    {
        return filter_var(
            get_setting(
                'customer_accounts.enabled',
                (bool) config('coremarket.customer_accounts.enabled', false)
            ),
            FILTER_VALIDATE_BOOL
        );
    }

    public function snapshot(): array
    {
        return ['enabled' => $this->enabled()];
    }
}
