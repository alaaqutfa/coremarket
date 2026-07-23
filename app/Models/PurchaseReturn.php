<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseReturn extends Model
{
    protected $guarded = [];

    protected $casts = [
        'return_date' => 'date',
        'exchange_rate' => 'decimal:6',
        'subtotal' => 'decimal:6',
        'tax_total' => 'decimal:6',
        'total' => 'decimal:6',
        'total_usd' => 'decimal:6',
        'metadata' => 'array',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function purchaseOrder() { return $this->belongsTo(PurchaseOrder::class); }
    public function items() { return $this->hasMany(PurchaseReturnItem::class); }
    public function ledgerEntries() { return $this->morphMany(SupplierLedgerEntry::class, 'reference'); }
}
