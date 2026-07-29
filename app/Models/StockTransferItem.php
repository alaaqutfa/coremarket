<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockTransferItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:6',
        'quantity_shipped' => 'decimal:6',
        'quantity_received' => 'decimal:6',
        'unit_cost' => 'decimal:6',
        'metadata' => 'array',
    ];

    public function transfer()
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
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
