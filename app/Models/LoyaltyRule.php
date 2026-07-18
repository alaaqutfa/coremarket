<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyRule extends Model
{
    protected $fillable = [
        'name',
        'is_active',
        'earn_rate_amount',
        'earn_rate_points',
        'min_order_amount',
        'redeem_points',
        'redeem_value',
        'min_redeem_points',
        'max_redeem_points_per_order',
        'max_redeem_percent',
        'allow_pos_redeem',
        'allow_storefront_redeem',
        'currency',
        'applies_to_order_from',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'earn_rate_amount' => 'decimal:6',
        'earn_rate_points' => 'integer',
        'min_order_amount' => 'decimal:6',
        'redeem_points' => 'integer',
        'redeem_value' => 'decimal:6',
        'min_redeem_points' => 'integer',
        'max_redeem_points_per_order' => 'integer',
        'max_redeem_percent' => 'decimal:4',
        'allow_pos_redeem' => 'boolean',
        'allow_storefront_redeem' => 'boolean',
        'metadata' => 'array',
    ];

    public function isActive(): bool
    {
        return $this->is_active;
    }

    public function appliesToOrderFrom(?string $orderFrom): bool
    {
        return $this->applies_to_order_from === null
            || $this->applies_to_order_from === $orderFrom;
    }

    public function hasRedemptionEnabledForOrderFrom(?string $orderFrom): bool
    {
        if (! $this->isActive()
            || ! $this->appliesToOrderFrom($orderFrom)
            || (int) $this->redeem_points <= 0
            || (float) $this->redeem_value <= 0) {
            return false;
        }

        return match ($orderFrom) {
            'pos' => $this->allow_pos_redeem,
            'web', 'storefront' => $this->allow_storefront_redeem,
            default => false,
        };
    }
}
