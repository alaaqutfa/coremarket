<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ProductFamily extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('name');
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'product_family_id');
    }

    public function subFamilyProducts()
    {
        return $this->hasMany(Product::class, 'product_sub_family_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeFamilies(Builder $query): Builder
    {
        return $query->where('level', 'family')->whereNull('parent_id');
    }

    public function scopeSubFamilies(Builder $query): Builder
    {
        return $query->where('level', 'sub_family')->whereNotNull('parent_id');
    }
}
