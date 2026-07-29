<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\PreventDemoModeChanges;

class ProductStock extends Model
{
    use PreventDemoModeChanges;

    protected $fillable = ['product_id', 'variant', 'sku', 'barcode', 'price', 'qty', 'image'];
    //
    public function product(){
    	return $this->belongsTo(Product::class);
    }

    public function wholesalePrices() {
        return $this->hasMany(WholesalePrice::class);
    }

    public function branchBalances()
    {
        return $this->hasMany(ProductStockBranchBalance::class);
    }

    public function branchPrices()
    {
        return $this->hasMany(ProductBranchPrice::class);
    }
}
