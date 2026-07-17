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
        'currency',
        'applies_to_order_from',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'earn_rate_amount' => 'decimal:6',
        'earn_rate_points' => 'integer',
        'min_order_amount' => 'decimal:6',
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
}
