<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashMovement extends Model
{
    protected $fillable = [
        'cashbox_id',
        'cashier_shift_id',
        'movement_type',
        'direction',
        'amount',
        'currency',
        'description',
        'reference_type',
        'reference_id',
        'accounting_event_id',
        'journal_entry_id',
        'created_by',
        'occurred_at',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:6',
        'occurred_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function cashbox(): BelongsTo
    {
        return $this->belongsTo(Cashbox::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'cashier_shift_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function accountingEvent(): BelongsTo
    {
        return $this->belongsTo(AccountingEvent::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function isIn(): bool
    {
        return $this->direction === 'in';
    }

    public function isOut(): bool
    {
        return $this->direction === 'out';
    }

    public function isNeutral(): bool
    {
        return $this->direction === 'neutral';
    }
}
