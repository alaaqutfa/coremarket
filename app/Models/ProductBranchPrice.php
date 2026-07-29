<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductBranchPrice extends Model
{
    protected $guarded = [];

    protected $casts = [
        'price' => 'decimal:6',
        'compare_at_price' => 'decimal:6',
        'margin_percent' => 'decimal:4',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function branch()
    {
        return $this->belongsTo(StoreBranch::class, 'store_branch_id');
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
