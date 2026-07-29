<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockTransfer extends Model
{
    protected $guarded = [];

    protected $casts = [
        'approved_at' => 'datetime',
        'shipped_at' => 'datetime',
        'received_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function items()
    {
        return $this->hasMany(StockTransferItem::class);
    }

    public function fromBranch()
    {
        return $this->belongsTo(StoreBranch::class, 'from_branch_id');
    }

    public function toBranch()
    {
        return $this->belongsTo(StoreBranch::class, 'to_branch_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
