<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    protected $guarded = [];

    protected $casts = [
        'ordered_at' => 'datetime', 'received_at' => 'datetime', 'cancelled_at' => 'datetime',
        'subtotal_amount' => 'decimal:6', 'tax_amount' => 'decimal:6', 'discount_amount' => 'decimal:6',
        'shipping_amount' => 'decimal:6', 'total_amount' => 'decimal:6', 'metadata' => 'array',
    ];

    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function items() { return $this->hasMany(PurchaseOrderItem::class); }
    public function receipts() { return $this->hasMany(PurchaseReceipt::class); }
}
