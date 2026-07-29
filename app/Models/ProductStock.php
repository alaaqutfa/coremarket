<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\PreventDemoModeChanges;

class ProductStock extends Model
{
    use PreventDemoModeChanges;

    protected $fillable = ['product_id', 'variant', 'sku', 'barcode', 'serial_tracking_enabled', 'imei_tracking_enabled', 'price', 'qty', 'image'];

    protected $casts = [
        'serial_tracking_enabled' => 'boolean',
        'imei_tracking_enabled' => 'boolean',
    ];
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

    public function serialUnits()
    {
        return $this->hasMany(ProductSerialUnit::class);
    }

    public function warrantyPolicies()
    {
        return $this->hasMany(ProductWarrantyPolicy::class);
    }
}
