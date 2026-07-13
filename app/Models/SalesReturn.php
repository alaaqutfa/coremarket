<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesReturn extends Model
{
    protected $guarded = [];

    protected $casts = [
        'subtotal_amount' => 'decimal:6',
        'tax_amount' => 'decimal:6',
        'discount_amount' => 'decimal:6',
        'shipping_amount' => 'decimal:6',
        'total_amount' => 'decimal:6',
        'total_cost' => 'decimal:6',
        'profit_reversal_amount' => 'decimal:6',
        'stock_reversed_at' => 'datetime',
        'completed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function items()
    {
        return $this->hasMany(SalesReturnItem::class);
    }
}
