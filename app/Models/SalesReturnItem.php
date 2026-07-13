<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesReturnItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:6',
        'unit_price' => 'decimal:6',
        'tax_amount' => 'decimal:6',
        'discount_amount' => 'decimal:6',
        'cost_price' => 'decimal:6',
        'total_cost' => 'decimal:6',
        'profit_reversal_amount' => 'decimal:6',
        'stock_reversed_quantity' => 'decimal:6',
        'metadata' => 'array',
    ];

    public function salesReturn()
    {
        return $this->belongsTo(SalesReturn::class);
    }

    public function orderDetail()
    {
        return $this->belongsTo(OrderDetail::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function productStock()
    {
        return $this->belongsTo(ProductStock::class);
    }

    public function accountingEvents() { return $this->morphMany(AccountingEvent::class, 'reference'); }
}
