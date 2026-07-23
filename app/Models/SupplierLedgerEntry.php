<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierLedgerEntry extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:6',
        'exchange_rate' => 'decimal:6',
        'amount_usd' => 'decimal:6',
        'metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function reference() { return $this->morphTo(); }
}
