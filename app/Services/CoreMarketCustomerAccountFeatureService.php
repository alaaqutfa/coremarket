<?php

namespace App\Services;

class CoreMarketCustomerAccountFeatureService
{
    public function enabled(): bool
    {
        return $this->setting('customer_accounts.enabled', 'customer_accounts.enabled');
    }

    public function creditLimitsEnabled(): bool
    {
        return $this->enabled()
            && $this->setting('customer_accounts.credit_limits_enabled', 'customer_accounts.credit_limits_enabled');
    }

    public function paymentTermsEnabled(): bool
    {
        return $this->enabled()
            && $this->setting('customer_accounts.payment_terms_enabled', 'customer_accounts.payment_terms_enabled');
    }

    public function payOnAccountEnabled(): bool
    {
        return $this->enabled()
            && $this->setting('customer_accounts.pay_on_account_enabled', 'customer_accounts.pay_on_account_enabled');
    }

    public function posPayOnAccountEnabled(): bool
    {
        return $this->payOnAccountEnabled()
            && $this->setting('pos.pay_on_account_enabled', 'pos.pay_on_account_enabled');
    }

    public function checkoutPayOnAccountEnabled(): bool
    {
        return $this->payOnAccountEnabled()
            && $this->setting('checkout.pay_on_account_enabled', 'checkout.pay_on_account_enabled');
    }

    public function snapshot(): array
    {
        return [
            'enabled' => $this->enabled(),
            'credit_limits_enabled' => $this->creditLimitsEnabled(),
            'payment_terms_enabled' => $this->paymentTermsEnabled(),
            'pay_on_account_enabled' => $this->payOnAccountEnabled(),
            'pos_pay_on_account_enabled' => $this->posPayOnAccountEnabled(),
            'checkout_pay_on_account_enabled' => $this->checkoutPayOnAccountEnabled(),
        ];
    }

    private function setting(string $key, string $configPath): bool
    {
        return filter_var(
            get_setting(
                $key,
                (bool) config('coremarket.'.$configPath, false)
            ),
            FILTER_VALIDATE_BOOL
        );
    }
}
