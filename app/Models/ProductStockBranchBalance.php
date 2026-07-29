<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductStockBranchBalance extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:6',
        'reserved_quantity' => 'decimal:6',
        'last_movement_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function productStock()
    {
        return $this->belongsTo(ProductStock::class);
    }

    public function branch()
    {
        return $this->belongsTo(StoreBranch::class, 'store_branch_id');
    }
}
