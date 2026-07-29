<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockCountItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'expected_quantity' => 'decimal:6',
        'counted_quantity' => 'decimal:6',
        'variance_quantity' => 'decimal:6',
        'unit_cost' => 'decimal:6',
    ];

    public function stockCount()
    {
        return $this->belongsTo(StockCount::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function productStock()
    {
        return $this->belongsTo(ProductStock::class);
    }
}
