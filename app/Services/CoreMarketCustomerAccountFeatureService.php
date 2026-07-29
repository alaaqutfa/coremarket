<?php

namespace App\Services;

class CoreMarketCustomerAccountFeatureService
{
    public function enabled(): bool
    {
        return $this->setting('customer_accounts.enabled', 'enabled');
    }

    public function creditLimitsEnabled(): bool
    {
        return $this->enabled()
            && $this->setting('customer_accounts.credit_limits_enabled', 'credit_limits_enabled');
    }

    public function paymentTermsEnabled(): bool
    {
        return $this->enabled()
            && $this->setting('customer_accounts.payment_terms_enabled', 'payment_terms_enabled');
    }

    public function snapshot(): array
    {
        return [
            'enabled' => $this->enabled(),
            'credit_limits_enabled' => $this->creditLimitsEnabled(),
            'payment_terms_enabled' => $this->paymentTermsEnabled(),
        ];
    }

    private function setting(string $key, string $configKey): bool
    {
        return filter_var(
            get_setting(
                $key,
                (bool) config('coremarket.customer_accounts.'.$configKey, false)
            ),
            FILTER_VALIDATE_BOOL
        );
    }
}
