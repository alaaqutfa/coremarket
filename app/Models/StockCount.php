<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockCount extends Model
{
    protected $guarded = [];

    protected $casts = [
        'counted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'posted_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function items()
    {
        return $this->hasMany(StockCountItem::class);
    }

    public function branch()
    {
        return $this->belongsTo(StoreBranch::class, 'branch_id');
    }
}
