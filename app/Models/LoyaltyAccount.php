<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyAccount extends Model
{
    protected $fillable = [
        'user_id',
        'points_balance',
        'lifetime_points_earned',
        'lifetime_points_redeemed',
        'status',
        'metadata',
    ];

    protected $casts = [
        'points_balance' => 'integer',
        'lifetime_points_earned' => 'integer',
        'lifetime_points_redeemed' => 'integer',
        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function movements()
    {
        return $this->hasMany(LoyaltyPointMovement::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
