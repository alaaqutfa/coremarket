<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashierShift extends Model
{
    protected $fillable = [
        'cashbox_id',
        'opened_by',
        'closed_by',
        'status',
        'opened_at',
        'closed_at',
        'opening_balance',
        'expected_cash',
        'actual_cash',
        'cash_difference',
        'notes',
        'close_notes',
        'metadata',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'opening_balance' => 'decimal:6',
        'expected_cash' => 'decimal:6',
        'actual_cash' => 'decimal:6',
        'cash_difference' => 'decimal:6',
        'metadata' => 'array',
    ];

    public function cashbox(): BelongsTo
    {
        return $this->belongsTo(Cashbox::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }
}
