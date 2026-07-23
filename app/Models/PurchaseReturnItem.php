<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseReturnItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:6',
        'unit_cost' => 'decimal:6',
        'tax_amount' => 'decimal:6',
        'line_total' => 'decimal:6',
        'stock_returned_quantity' => 'decimal:6',
        'metadata' => 'array',
    ];

    public function purchaseReturn() { return $this->belongsTo(PurchaseReturn::class); }
    public function purchaseOrderItem() { return $this->belongsTo(PurchaseOrderItem::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function productStock() { return $this->belongsTo(ProductStock::class); }
    public function inventoryMovement() { return $this->belongsTo(InventoryMovement::class); }
}
