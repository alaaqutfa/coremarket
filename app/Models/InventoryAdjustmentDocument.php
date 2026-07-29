<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryAdjustmentDocument extends Model
{
    protected $guarded = [];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'posted_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function items()
    {
        return $this->hasMany(InventoryAdjustmentItem::class);
    }

    public function branch()
    {
        return $this->belongsTo(StoreBranch::class, 'branch_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function poster()
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
