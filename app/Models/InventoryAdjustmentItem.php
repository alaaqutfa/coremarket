<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryAdjustmentItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity_before' => 'decimal:6',
        'quantity_change' => 'decimal:6',
        'quantity_after' => 'decimal:6',
        'unit_cost' => 'decimal:6',
        'amount' => 'decimal:6',
        'metadata' => 'array',
    ];

    public function document()
    {
        return $this->belongsTo(InventoryAdjustmentDocument::class, 'inventory_adjustment_document_id');
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
