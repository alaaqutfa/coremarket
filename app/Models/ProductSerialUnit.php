<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductSerialUnit extends Model
{
    protected $guarded = [];

    protected $casts = [
        'warranty_expires_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function product() { return $this->belongsTo(Product::class); }
    public function productStock() { return $this->belongsTo(ProductStock::class); }
    public function branch() { return $this->belongsTo(StoreBranch::class, 'store_branch_id'); }
    public function purchaseOrder() { return $this->belongsTo(PurchaseOrder::class); }
    public function purchaseReceipt() { return $this->belongsTo(PurchaseReceipt::class); }
    public function order() { return $this->belongsTo(Order::class); }
    public function orderDetail() { return $this->belongsTo(OrderDetail::class); }
    public function salesReturn() { return $this->belongsTo(SalesReturn::class); }
    public function warrantyClaims() { return $this->hasMany(WarrantyClaim::class); }
}
