<?php

namespace App\Services;

use App\Models\User;
use DomainException;

class CoreMarketSellerGovernanceService
{
    public function __construct(private readonly CoreMarketFeatureAccessService $features) {}

    public function sellerLimit(): ?int
    {
        $limit = $this->features->limit('sellers_limit');

        return $limit === null || $limit === '' ? null : max(0, (int) $limit);
    }

    public function currentSellerCount(): int
    {
        return User::query()->where('user_type', 'seller')->where('banned', '!=', 1)->count();
    }

    public function assertCanActivateSeller(?User $seller = null): void
    {
        if ($seller && $seller->user_type === 'seller' && (int) $seller->banned !== 1) {
            return;
        }

        $limit = $this->sellerLimit();
        if ($limit !== null && $this->currentSellerCount() >= $limit) {
            throw new DomainException('The marketplace seller limit for the current plan has been reached.');
        }
    }
}
