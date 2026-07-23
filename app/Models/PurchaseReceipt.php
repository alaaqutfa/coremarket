<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseReceipt extends Model
{
    protected $guarded = [];

    protected $casts = ['received_at' => 'datetime', 'metadata' => 'array'];

    public function purchaseOrder() { return $this->belongsTo(PurchaseOrder::class); }
    public function items() { return $this->hasMany(PurchaseReceiptItem::class); }
    public function ledgerEntries() { return $this->morphMany(SupplierLedgerEntry::class, 'reference'); }
}
