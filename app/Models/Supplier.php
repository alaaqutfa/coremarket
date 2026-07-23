<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean', 'metadata' => 'array'];

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function ledgerEntries() { return $this->hasMany(SupplierLedgerEntry::class); }
    public function payments() { return $this->hasMany(SupplierPayment::class); }
    public function purchaseReturns() { return $this->hasMany(PurchaseReturn::class); }
}
