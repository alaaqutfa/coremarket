<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyPointMovement extends Model
{
    protected $fillable = [
        'loyalty_account_id',
        'user_id',
        'movement_type',
        'direction',
        'points',
        'balance_after',
        'reference_type',
        'reference_id',
        'idempotency_key',
        'reason',
        'created_by',
        'expires_at',
        'metadata',
    ];

    protected $casts = [
        'points' => 'integer',
        'balance_after' => 'integer',
        'expires_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function account()
    {
        return $this->belongsTo(LoyaltyAccount::class, 'loyalty_account_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reference()
    {
        return $this->morphTo();
    }

    public function isInbound(): bool
    {
        return $this->direction === 'in';
    }

    public function isOutbound(): bool
    {
        return $this->direction === 'out';
    }
}
