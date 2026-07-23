<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity_ordered' => 'decimal:6', 'quantity_received' => 'decimal:6', 'unit_cost' => 'decimal:6',
        'tax_amount' => 'decimal:6', 'discount_amount' => 'decimal:6', 'total_cost' => 'decimal:6', 'metadata' => 'array',
    ];

    public function purchaseOrder() { return $this->belongsTo(PurchaseOrder::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function productStock() { return $this->belongsTo(ProductStock::class); }
    public function purchaseReturnItems() { return $this->hasMany(PurchaseReturnItem::class); }
}
