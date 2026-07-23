<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceList extends Model
{
    protected $guarded = [];

    protected $casts = [
        'margin_percent' => 'decimal:4',
        'discount_percent' => 'decimal:4',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function items()
    {
        return $this->hasMany(PriceListItem::class);
    }

    public function customers()
    {
        return $this->hasMany(User::class);
    }
}
