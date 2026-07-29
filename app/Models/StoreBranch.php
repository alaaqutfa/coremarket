<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class StoreBranch extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    public function staffUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'staff_branch_assignments')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function stockBalances()
    {
        return $this->hasMany(ProductStockBranchBalance::class);
    }

    public function outgoingStockTransfers()
    {
        return $this->hasMany(StockTransfer::class, 'from_branch_id');
    }

    public function incomingStockTransfers()
    {
        return $this->hasMany(StockTransfer::class, 'to_branch_id');
    }

    public function productPrices()
    {
        return $this->hasMany(ProductBranchPrice::class);
    }
}
