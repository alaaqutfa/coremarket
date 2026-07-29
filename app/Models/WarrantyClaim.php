<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarrantyClaim extends Model
{
    protected $guarded = [];

    protected $casts = [
        'received_at' => 'datetime',
        'closed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function customer() { return $this->belongsTo(User::class, 'customer_id'); }
    public function order() { return $this->belongsTo(Order::class); }
    public function orderDetail() { return $this->belongsTo(OrderDetail::class); }
    public function serialUnit() { return $this->belongsTo(ProductSerialUnit::class, 'product_serial_unit_id'); }
    public function product() { return $this->belongsTo(Product::class); }
    public function productStock() { return $this->belongsTo(ProductStock::class); }
    public function receivedBy() { return $this->belongsTo(User::class, 'received_by_user_id'); }
    public function closedBy() { return $this->belongsTo(User::class, 'closed_by_user_id'); }
}
