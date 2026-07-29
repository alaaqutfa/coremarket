<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductWarrantyPolicy extends Model
{
    protected $guarded = [];

    protected $casts = ['metadata' => 'array'];

    public function product() { return $this->belongsTo(Product::class); }
    public function productStock() { return $this->belongsTo(ProductStock::class); }
}
