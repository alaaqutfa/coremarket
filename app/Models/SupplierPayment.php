<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierPayment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:6',
        'exchange_rate' => 'decimal:6',
        'amount_usd' => 'decimal:6',
        'paid_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function purchaseOrder() { return $this->belongsTo(PurchaseOrder::class); }
    public function ledgerEntries() { return $this->morphMany(SupplierLedgerEntry::class, 'reference'); }
}
