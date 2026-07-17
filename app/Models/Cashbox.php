<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cashbox extends Model
{
    protected $fillable = [
        'name',
        'code',
        'location',
        'currency',
        'status',
        'assigned_user_id',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function shifts(): HasMany
    {
        return $this->hasMany(CashierShift::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    public function posOrders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isInactive(): bool
    {
        return ! $this->isActive();
    }
}
