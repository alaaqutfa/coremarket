<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceListItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'fixed_price' => 'decimal:6',
        'margin_percent' => 'decimal:4',
        'discount_percent' => 'decimal:4',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function priceList() { return $this->belongsTo(PriceList::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function productStock() { return $this->belongsTo(ProductStock::class); }
}
